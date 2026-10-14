<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\SuperAdmin\Concerns\ResolvesRestaurantLocation;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantApplicationNote;
use App\Models\RestaurantApplicationReview;
use App\Models\RestaurantDocument;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantNotification;
use App\Models\RestaurantWorkingHour;
use Carbon\Carbon;
use App\Models\PlatformSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin > Restaurant Applications
 *
 * An application is a restaurant account going through onboarding:
 * incomplete (still filling the app) -> new (submitted) -> under_review /
 * info_requested -> approved | rejected
 */
class SuperAdminRestaurantApplicationController extends Controller
{
    use ResolvesRestaurantLocation;

    private const EXPORT_LIMIT = 5000;

    private const STATUSES = ['incomplete', 'new', 'under_review', 'info_requested', 'approved', 'rejected'];

    private const FILTER_RULES = [
        'search' => ['nullable', 'string', 'max:100'],
        'status' => ['nullable', 'in:incomplete,new,under_review,info_requested,approved,rejected'],
        'city_id' => ['nullable', 'integer'],
        'date' => ['nullable', 'in:today,7d,30d'],
        'docs' => ['nullable', 'in:verified,incomplete'],
    ];

    /**
     * Document key => label shown on the review page
     */
    private const DOCUMENTS = [
        'fssai' => 'FSSAI License',
        'gst' => 'GST Registration',
        'pan' => 'Owner PAN Card',
        'bank' => 'Bank Details (UPI)',
    ];

    private const SETUP_STEPS = [
        'restaurant_setup' => 'restaurant_setup_verified_at',
        'menu_setup' => 'menu_setup_verified_at',
        'live' => 'live_verified_at',
    ];

    /**
     * Application list (search + filters + pagination), status cards and filter options
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            ...self::FILTER_RULES,
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $applications = $this->filteredQuery($validated)
            ->orderByRaw('submitted_at DESC')
            ->orderByDesc('restaurants.id')
            ->paginate($validated['per_page'] ?? 10);

        $lookups = $this->locationLookups();

        $applications->getCollection()->transform(fn ($restaurant) => $this->listRow($restaurant, $lookups));

        return response()->json([
            'message' => 'Restaurant applications fetched successfully.',
            'data' => $applications,
            'stats' => $this->stats(),
            'filters' => [
                'cities' => $lookups['cities']->map(fn ($city) => [
                    'id' => $city->id,
                    'name' => $city->name,
                ])->values(),
            ],
        ]);
    }

    /**
     * All filtered applications (no pagination) for the CSV export
     */
    public function export(Request $request)
    {
        $validated = $request->validate(self::FILTER_RULES);

        $lookups = $this->locationLookups();

        $applications = $this->filteredQuery($validated)
            ->orderByRaw('submitted_at DESC')
            ->limit(self::EXPORT_LIMIT)
            ->get()
            ->map(fn ($restaurant) => $this->listRow($restaurant, $lookups));

        return response()->json([
            'message' => 'Restaurant applications exported successfully.',
            'data' => $applications,
        ]);
    }

    /**
     * Full application: documents, requests, notes, commission and setup checks
     */
    public function show($id)
    {
        $restaurant = $this->findApplication($id);

        if (!$restaurant) {
            return $this->notFound();
        }

        return response()->json([
            'message' => 'Restaurant application fetched successfully.',
            'data' => $this->detailRow($restaurant),
        ]);
    }

    /**
     * Assign the application to the signed in super admin
     */
    public function assign(Request $request, $id)
    {
        $restaurant = $this->findApplication($id);

        if (!$restaurant) {
            return $this->notFound();
        }

        $admin = $request->user();

        $this->review($restaurant)->update(['assigned_admin_id' => $admin->id]);
        $this->startReview($restaurant);

        $this->log($request, $restaurant, 'Application Assigned', 'Assigned to ' . $admin->name);

        return $this->detailResponse($restaurant->id, 'Application assigned successfully.');
    }

    /**
     * Mark one submitted document as checked
     */
    public function verifyDocument(Request $request, $id, $key)
    {
        $restaurant = $this->findApplication($id);

        if (!$restaurant) {
            return $this->notFound();
        }

        if ($error = $this->ensureOpen($restaurant, allowIncomplete: true)) {
            return $error;
        }

        $documents = collect($this->documents($restaurant));

        if (!$documents->firstWhere('key', $key)) {
            return response()->json([
                'message' => 'This document has not been submitted.',
            ], 422);
        }

        $review = $this->review($restaurant);

        $review->update([
            'verified_documents' => collect($review->verified_documents ?? [])
                ->push($key)
                ->unique()
                ->values()
                ->all(),
        ]);

        $this->startReview($restaurant);

        $this->log($request, $restaurant, 'Document Verified', self::DOCUMENTS[$key] . ' verified');

        return $this->detailResponse($restaurant->id, 'Document verified successfully.');
    }

    /**
     * Approve: documents approved and restaurant becomes active
     */
    public function approve(Request $request, $id)
    {
        $restaurant = $this->findApplication($id);

        if (!$restaurant) {
            return $this->notFound();
        }

        if ($error = $this->ensureOpen($restaurant)) {
            return $error;
        }

        $documents = collect($this->documents($restaurant));

        if ($documents->isEmpty() || $documents->contains('verified', false)) {
            return response()->json([
                'message' => 'Please verify all documents before approval.',
            ], 422);
        }

        DB::transaction(function () use ($restaurant) {
            $defaultCommission = (float) (PlatformSetting::getSettings()->restaurant_commission ?? config('restaurant.commission_percentage', 30.00));

            $restaurant->document->update([
                'status' => 'approved',
                'rejection_reason' => null,
                'reviewed_at' => now(),
            ]);

            $restaurant->update([
                'status' => 'approved',
                'commission_percentage' => $restaurant->commission_percentage ?? $defaultCommission,
            ]);

            $this->review($restaurant)->update(['info_requested_at' => null]);

            $this->notify(
                $restaurant,
                'Application approved',
                'Congratulations! Your restaurant has been approved on VegGo.'
            );
        });

        $this->log($request, $restaurant, 'Restaurant Approval', 'Application: Under Review → Approved (Commission: ' . $restaurant->commissionRate() . '%)', 'Medium');

        return $this->detailResponse($restaurant->id, 'Application approved successfully. Default commission (' . $restaurant->commissionRate() . '%) applied.');
    }

    /**
     * Reject with a reason the restaurant can see
     */
    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $restaurant = $this->findApplication($id);

        if (!$restaurant) {
            return $this->notFound();
        }

        if ($error = $this->ensureOpen($restaurant)) {
            return $error;
        }

        $reason = trim($validated['reason']);

        DB::transaction(function () use ($restaurant, $reason) {
            $restaurant->document?->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_at' => now(),
            ]);

            $restaurant->update(['status' => 'rejected']);

            $this->review($restaurant)->update(['info_requested_at' => null]);

            $this->notify($restaurant, 'Application rejected', 'Your restaurant application was rejected. Reason: ' . $reason);
        });

        $this->log($request, $restaurant, 'Restaurant Rejected', 'Reason: ' . $reason, 'Medium');

        return $this->detailResponse($restaurant->id, 'Application rejected successfully.');
    }

    /**
     * Ask the restaurant for more information (sent as an app notification)
     */
    public function requestInfo(Request $request, $id)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $restaurant = $this->findApplication($id);

        if (!$restaurant) {
            return $this->notFound();
        }

        if ($error = $this->ensureOpen($restaurant)) {
            return $error;
        }

        $message = trim($validated['message']);

        DB::transaction(function () use ($request, $restaurant, $message) {
            $this->addNote($request, $restaurant, 'info_request', $message);

            $this->review($restaurant)->update(['info_requested_at' => now()]);
            $this->startReview($restaurant);

            $this->notify($restaurant, 'More information needed', $message);
        });

        $this->log($request, $restaurant, 'Information Requested', $message);

        return $this->detailResponse($restaurant->id, 'Information request sent successfully.');
    }

    /**
     * Internal admin note
     */
    public function storeNote(Request $request, $id)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $restaurant = $this->findApplication($id);

        if (!$restaurant) {
            return $this->notFound();
        }

        $this->addNote($request, $restaurant, 'note', trim($validated['message']));

        return $this->detailResponse($restaurant->id, 'Admin notes saved successfully.');
    }

    /**
     * Commission % VegGo takes on this restaurant's orders (after approval)
     */
    public function updateCommission(Request $request, $id)
    {
        $validated = $request->validate([
            'commission' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $restaurant = $this->findApplication($id);

        if (!$restaurant) {
            return $this->notFound();
        }

        if ($this->applicationStatus($restaurant) !== 'approved') {
            return response()->json([
                'message' => 'Commission can be set after approval.',
            ], 422);
        }

        $old = $restaurant->commissionRate();

        $restaurant->update(['commission_percentage' => $validated['commission']]);

        $this->log(
            $request,
            $restaurant,
            'Commission Updated',
            'Commission: ' . $old . '% → ' . (float) $validated['commission'] . '%'
        );

        return $this->detailResponse($restaurant->id, 'Commission changes saved successfully.');
    }

    /**
     * Verify an onboarding setup step, in order:
     * restaurant_setup -> menu_setup -> live
     */
    public function verifySetup(Request $request, $id, $step)
    {
        $restaurant = $this->findApplication($id);

        if (!$restaurant) {
            return $this->notFound();
        }

        $setup = $this->setup($restaurant);
        $current = $setup[$step] ?? null;

        if (!$current) {
            return response()->json(['message' => 'Unknown setup step.'], 404);
        }

        if ($current['verified_at']) {
            return response()->json(['message' => 'This step is already verified.'], 422);
        }

        if (!$current['ready']) {
            return response()->json(['message' => $current['blocked_reason']], 422);
        }

        $this->review($restaurant)->update([self::SETUP_STEPS[$step] => now()]);

        $label = match ($step) {
            'restaurant_setup' => 'Restaurant Setup',
            'menu_setup' => 'Menu Setup',
            'live' => 'Live',
        };

        $this->log($request, $restaurant, $label . ' Verified', $label . ' verified');

        $message = match ($step) {
            'restaurant_setup' => 'Restaurant setup verified successfully. Menu Setup is now available.',
            'menu_setup' => 'Menu setup verified successfully. Live verification is now available.',
            'live' => 'Restaurant is now verified and ready to go live.',
        };

        return $this->detailResponse($restaurant->id, $message);
    }

    // =====================================================
    // QUERY
    // =====================================================

    private function filteredQuery(array $filters): Builder
    {
        $statusSql = $this->statusSql();
        $docsTotalSql = $this->docsTotalSql();
        $citySql = $this->citySql();

        $search = trim((string) ($filters['search'] ?? ''));

        return Restaurant::query()
            ->leftJoin('restaurant_addresses as ra', 'ra.restaurant_id', '=', 'restaurants.id')
            ->leftJoin('restaurant_documents as rd', 'rd.restaurant_id', '=', 'restaurants.id')
            ->leftJoin('restaurant_application_reviews as rar', 'rar.restaurant_id', '=', 'restaurants.id')
            ->leftJoin('restaurant_partner_contracts as rpc', 'rpc.restaurant_id', '=', 'restaurants.id')
            ->select('restaurants.*')
            ->addSelect([
                'ra.full_address',
                'ra.shop_plot_number',
                'ra.floor',
                'ra.complete_address',
                'ra.landmark',
                'ra.pincode',
            ])
            ->selectRaw('COALESCE(rpc.signed_at, restaurants.created_at) as submitted_at')
            ->selectRaw("{$statusSql} as application_status")
            ->selectRaw("{$citySql} as city_id")
            ->with([
                'document.outletType:id,name',
                'applicationReview.assignedAdmin:id,name',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $like = '%' . $search . '%';
                    $digits = preg_replace('/\D/', '', $search);

                    $query->where('restaurants.restaurant_name', 'like', $like)
                        ->orWhere('restaurants.owner_name', 'like', $like)
                        ->orWhere('restaurants.email', 'like', $like);

                    if ($digits !== '') {
                        $phoneDigits = strlen($digits) > 10 ? substr($digits, -10) : $digits;
                        $query->orWhere('restaurants.mobile', 'like', '%' . $phoneDigits . '%');
                    }

                    // "#APP-0012" / "APP-12" / "12"
                    if (preg_match('/^#?(?:app-?)?0*(\d+)$/i', $search, $match)) {
                        $query->orWhere('restaurants.id', (int) $match[1]);
                    }
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereRaw("{$statusSql} = ?", [$status]))
            ->when($filters['city_id'] ?? null, fn ($query, $cityId) => $query->whereRaw("{$citySql} = ?", [$cityId]))
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereRaw(
                'COALESCE(rpc.signed_at, restaurants.created_at) >= ?',
                [match ($date) {
                    'today' => now()->startOfDay(),
                    '7d' => now()->subDays(7),
                    '30d' => now()->subDays(30),
                }]
            ))
            ->when($filters['docs'] ?? null, function ($query, $docs) use ($docsTotalSql) {
                $verifiedSql = "(rd.status = 'approved' OR ({$docsTotalSql} > 0 AND {$this->docsVerifiedSql()} >= {$docsTotalSql}))";

                $docs === 'verified'
                    ? $query->whereRaw($verifiedSql)
                    : $query->whereRaw("NOT {$verifiedSql}");
            });
    }

    /**
     * Same rules as applicationStatus(), in SQL (needs rd and rar joined)
     */
    private function statusSql(): string
    {
        return "(CASE
            WHEN restaurants.status = 'draft' THEN 'incomplete'
            WHEN restaurants.status IN ('approved', 'suspended', 'inactive') THEN 'approved'
            WHEN restaurants.status = 'rejected' OR rd.status = 'rejected' THEN 'rejected'
            WHEN rar.info_requested_at IS NOT NULL THEN 'info_requested'
            WHEN rd.status IN ('under_review', 'approved') THEN 'under_review'
            ELSE 'new'
        END)";
    }

    /**
     * Document key => restaurant_documents column holding what was submitted
     */
    private const DOCUMENT_COLUMNS = [
        'fssai' => 'fssai_number',
        'gst' => 'gstin_number',
        'pan' => 'pan_number',
        'bank' => 'upi_id',
    ];

    private function docsTotalSql(): string
    {
        return '(' . collect(self::DOCUMENT_COLUMNS)
            ->map(fn ($column) => "(CASE WHEN rd.{$column} IS NOT NULL AND rd.{$column} != '' THEN 1 ELSE 0 END)")
            ->implode(' + ') . ')';
    }

    /**
     * Submitted documents an admin has ticked (keys no longer checked are ignored)
     */
    private function docsVerifiedSql(): string
    {
        return '(' . collect(self::DOCUMENT_COLUMNS)
            ->map(fn ($column, $key) => "(CASE WHEN rd.{$column} IS NOT NULL AND rd.{$column} != ''"
                . " AND JSON_CONTAINS(COALESCE(rar.verified_documents, JSON_ARRAY()), '\"{$key}\"') THEN 1 ELSE 0 END)")
            ->implode(' + ') . ')';
    }

    /**
     * Status card counts, with change vs the previous 30 days
     * (applications submitted in the last 30 days vs the 30 before)
     */
    private function stats(): array
    {
        $statusSql = $this->statusSql();
        $submittedSql = 'COALESCE(rpc.signed_at, restaurants.created_at)';

        $rows = DB::table('restaurants')
            ->leftJoin('restaurant_documents as rd', 'rd.restaurant_id', '=', 'restaurants.id')
            ->leftJoin('restaurant_application_reviews as rar', 'rar.restaurant_id', '=', 'restaurants.id')
            ->leftJoin('restaurant_partner_contracts as rpc', 'rpc.restaurant_id', '=', 'restaurants.id')
            ->selectRaw("{$statusSql} as application_status")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN {$submittedSql} >= ? THEN 1 ELSE 0 END) as current_period", [now()->subDays(30)])
            ->selectRaw(
                "SUM(CASE WHEN {$submittedSql} >= ? AND {$submittedSql} < ? THEN 1 ELSE 0 END) as previous_period",
                [now()->subDays(60), now()->subDays(30)]
            )
            ->groupBy('application_status')
            ->get()
            ->keyBy('application_status');

        $stats = ['total' => (int) $rows->sum('total')];

        foreach (self::STATUSES as $status) {
            $row = $rows->get($status);
            $previous = (int) ($row->previous_period ?? 0);

            $stats[$status] = [
                'count' => (int) ($row->total ?? 0),
                // null when there is nothing to compare against
                'change' => $previous > 0
                    ? round((((int) $row->current_period - $previous) / $previous) * 100)
                    : null,
            ];
        }

        return $stats;
    }

    private function findApplication($id): ?Restaurant
    {
        return $this->filteredQuery([])->where('restaurants.id', $id)->first();
    }

    // =====================================================
    // ROWS
    // =====================================================

    private function listRow(Restaurant $restaurant, array $lookups): array
    {
        $city = $restaurant->city_id ? $lookups['cities']->firstWhere('id', (int) $restaurant->city_id) : null;
        $area = $this->area($restaurant, $city?->id, $lookups['areas']);
        $documents = $this->documents($restaurant);
        $assigned = $restaurant->applicationReview?->assignedAdmin;

        return [
            'id' => $restaurant->id,
            'application_code' => '#APP-' . str_pad((string) $restaurant->id, 4, '0', STR_PAD_LEFT),
            'restaurant' => $restaurant->restaurant_name,
            'owner' => $restaurant->owner_name,
            'mobile' => $restaurant->mobile,
            'email' => $restaurant->email,
            'city' => $city?->name,
            'area' => $area,
            'location' => implode(', ', array_filter([$area, $city?->name])) ?: ($restaurant->complete_address ?: null),
            'submitted_at' => $this->iso($restaurant->submitted_at),
            'status' => $restaurant->application_status,
            'documents' => $documents,
            'documents_verified' => collect($documents)->where('verified', true)->count(),
            'documents_total' => count($documents),
            'assigned_to' => $assigned ? [
                'id' => $assigned->id,
                'name' => $assigned->name,
            ] : null,
        ];
    }

    private function detailRow(Restaurant $restaurant): array
    {
        $notes = $restaurant->applicationNotes()
            ->latest('id')
            ->get(['id', 'type', 'message', 'admin_name', 'created_at']);

        $noteRow = fn ($note) => [
            'id' => $note->id,
            'message' => $note->message,
            'admin_name' => $note->admin_name,
            'created_at' => $this->iso($note->created_at),
        ];

        return [
            ...$this->listRow($restaurant, $this->locationLookups()),
            'full_address' => $this->fullAddress($restaurant),
            'outlet_type' => $restaurant->document?->outletType?->name,
            'rejection_reason' => $this->applicationStatus($restaurant) === 'rejected'
                ? $restaurant->document?->rejection_reason
                : null,
            'info_requests' => $notes->where('type', 'info_request')->sortBy('id')->map($noteRow)->values(),
            'notes' => $notes->where('type', 'note')->map($noteRow)->values(),
            'commission' => [
                'rate' => $restaurant->commissionRate(),
                'is_set' => $restaurant->commission_percentage !== null,
                'default' => (float) (PlatformSetting::getSettings()->restaurant_commission ?? config('restaurant.commission_percentage', 30.00)),
            ],
            'setup' => $this->setup($restaurant),
        ];
    }

    /**
     * Submitted documents with the admin's verification
     */
    private function documents(Restaurant $restaurant): array
    {
        $document = $restaurant->document;

        if (!$document) {
            return [];
        }

        $values = [
            'fssai' => $document->fssai_number,
            'gst' => $document->gstin_number,
            'pan' => $document->pan_number,
            'bank' => $document->upi_id
                ? trim($document->upi_id . ($document->upi_holder_name ? ' (' . $document->upi_holder_name . ')' : ''))
                : null,
        ];

        $filePaths = [
            'fssai' => $document->fssai_image_path,
            'gst' => $document->gst_image_path,
            'pan' => $document->pan_image_path,
            'bank' => null,
        ];

        // Approved documents were fully verified (also covers approvals made before this page)
        $verified = $document->status === 'approved'
            ? array_keys($values)
            : ($restaurant->applicationReview?->verified_documents ?? []);

        return collect($values)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value, $key) => [
                'key' => $key,
                'name' => self::DOCUMENTS[$key],
                'value' => $value,
                'file_url' => $this->documentUrl($filePaths[$key]),
                'verified' => in_array($key, $verified, true),
            ])
            ->values()
            ->all();
    }

    /**
     * Onboarding setup checks with what still blocks each step
     */
    private function setup(Restaurant $restaurant): array
    {
        $review = $restaurant->applicationReview;
        $approved = $this->applicationStatus($restaurant) === 'approved';

        $hasAddress = $restaurant->full_address !== null;
        $hasHours = RestaurantWorkingHour::where('restaurant_id', $restaurant->id)->exists();
        $menuItems = RestaurantMenuItem::where('restaurant_id', $restaurant->id)->where('is_active', true)->count();

        $restaurantSetupAt = $review?->restaurant_setup_verified_at;
        $menuSetupAt = $review?->menu_setup_verified_at;

        $restaurantBlock = match (true) {
            !$approved => 'Please approve the application first.',
            !$hasAddress => 'The restaurant has not added its address yet.',
            !$hasHours => 'The restaurant has not added its working hours yet.',
            default => null,
        };

        $menuBlock = match (true) {
            !$restaurantSetupAt => 'Please verify Restaurant Setup first.',
            $menuItems === 0 => 'The restaurant has no active menu items yet.',
            default => null,
        };

        $liveBlock = $menuSetupAt ? null : 'Please verify Menu Setup first.';

        $step = fn ($verifiedAt, $block) => [
            'verified_at' => $this->iso($verifiedAt),
            'ready' => !$verifiedAt && $block === null,
            'blocked_reason' => $verifiedAt ? null : $block,
        ];

        return [
            'registration_completed' => $restaurant->status !== 'draft',
            'menu_items_count' => $menuItems,
            'restaurant_setup' => $step($restaurantSetupAt, $restaurantBlock),
            'menu_setup' => $step($menuSetupAt, $menuBlock),
            'live' => $step($review?->live_verified_at, $liveBlock),
        ];
    }

    // =====================================================
    // HELPERS
    // =====================================================

    private function applicationStatus(Restaurant $restaurant): string
    {
        return $restaurant->application_status
            ?? $this->findApplication($restaurant->id)?->application_status
            ?? 'new';
    }

    /**
     * Decisions are only possible on a submitted, undecided application
     */
    private function ensureOpen(Restaurant $restaurant, bool $allowIncomplete = false)
    {
        $message = match ($this->applicationStatus($restaurant)) {
            'incomplete' => $allowIncomplete ? null : 'The restaurant has not submitted its application yet.',
            'approved' => 'This application is already approved.',
            'rejected' => 'This application is already rejected.',
            default => null,
        };

        return $message ? response()->json(['message' => $message], 422) : null;
    }

    private function review(Restaurant $restaurant): RestaurantApplicationReview
    {
        return RestaurantApplicationReview::firstOrCreate(['restaurant_id' => $restaurant->id]);
    }

    /**
     * First admin action moves a submitted application into review
     */
    private function startReview(Restaurant $restaurant): void
    {
        RestaurantDocument::where('restaurant_id', $restaurant->id)
            ->where('status', 'pending')
            ->update(['status' => 'under_review']);
    }

    private function addNote(Request $request, Restaurant $restaurant, string $type, string $message): void
    {
        $admin = $request->user();

        RestaurantApplicationNote::create([
            'restaurant_id' => $restaurant->id,
            'admin_id' => $admin?->id,
            'admin_name' => $admin?->name,
            'type' => $type,
            'message' => $message,
        ]);
    }

    private function notify(Restaurant $restaurant, string $title, string $message): void
    {
        RestaurantNotification::create([
            'restaurant_id' => $restaurant->id,
            'type' => 'system',
            'title' => $title,
            'message' => $message,
            'reference_type' => 'restaurant_application',
            'reference_id' => $restaurant->id,
            'is_read' => false,
        ]);
    }

    private function detailResponse(int $id, string $message)
    {
        return response()->json([
            'message' => $message,
            'data' => $this->detailRow($this->findApplication($id)),
        ]);
    }

    private function notFound()
    {
        return response()->json([
            'message' => 'Restaurant application not found.',
        ], 404);
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }

    private function documentUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }
}
