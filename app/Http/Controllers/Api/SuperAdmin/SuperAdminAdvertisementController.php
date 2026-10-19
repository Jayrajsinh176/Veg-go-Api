<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Exceptions\AdvertisementException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Restaurant;
use App\Models\RestaurantAdPackage;
use App\Models\RestaurantAdPackageDuration;
use App\Models\RestaurantAdPayment;
use App\Models\RestaurantAdvertisement;
use App\Models\RestaurantCategory;
use App\Services\Restaurant\RestaurantAdvertisementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Banners page: restaurant advertisements created and managed by super
 * admins and role admins. Every rule lives in RestaurantAdvertisementService;
 * this controller only validates input shape, checks permissions and
 * formats responses.
 */
class SuperAdminAdvertisementController extends Controller
{
    private const LIST_STATUSES = ['draft', 'pending', 'scheduled', 'active', 'paused', 'expired', 'cancelled'];

    public function __construct(private RestaurantAdvertisementService $service)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Create form lookups
    |--------------------------------------------------------------------------
    */

    /**
     * Restaurants that may advertise, the active packages with prices, and
     * how payment can be collected.
     */
    public function options()
    {
        $restaurants = Restaurant::query()
            ->where('status', 'approved')
            ->whereNotNull('restaurant_name')
            ->orderBy('restaurant_name')
            ->get(['id', 'restaurant_name', 'owner_name', 'mobile', 'is_online'])
            ->map(fn ($restaurant) => [
                'id' => $restaurant->id,
                'name' => $restaurant->restaurant_name,
                'owner_name' => $restaurant->owner_name,
                'mobile' => $restaurant->mobile,
                'is_online' => (bool) $restaurant->is_online,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Advertisement options fetched successfully.',
            'data' => [
                'restaurants' => $restaurants,
                'packages' => $this->service->activePackages()
                    ->map(fn ($package) => $this->formatPackage($package, false))
                    ->values(),
                'gst_percentage' => (float) config('advertisement.gst_percentage', 18),
                'currency' => config('advertisement.currency', 'INR'),
                'payment' => $this->paymentConfig(),
                'image_rules' => [
                    'mimes' => config('advertisement.image.mimes'),
                    'max_kb' => config('advertisement.image.max_kb'),
                    'min_width' => config('advertisement.image.min_width'),
                    'min_height' => config('advertisement.image.min_height'),
                ],
            ],
        ]);
    }

    /**
     * Package 2 picker: active categories and active food items of one
     * approved restaurant. Optional ?category_id=
     */
    public function foodItems(Request $request, $restaurantId)
    {
        $restaurant = Restaurant::query()->find((int) $restaurantId);

        if (!$restaurant) {
            throw AdvertisementException::notFound('Restaurant not found.');
        }

        $categories = RestaurantCategory::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->when($request->filled('category_id'), fn ($q) => $q->where('id', (int) $request->category_id))
            ->with(['menuItems' => fn ($q) => $q
                ->where('restaurant_id', $restaurant->id)
                ->where('is_active', true)
                ->orderBy('name')])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Food items fetched successfully.',
            'data' => [
                'restaurant' => ['id' => $restaurant->id, 'name' => $restaurant->restaurant_name],
                'categories' => $categories
                    ->filter(fn ($category) => $category->menuItems->isNotEmpty())
                    ->map(fn ($category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'items' => $category->menuItems->map(fn ($item) => [
                            'id' => $item->id,
                            'name' => $item->name,
                            'price' => $item->price,
                            'discount_price' => $item->discount_price,
                            'image_url' => $this->publicUrl($item->image),
                            'is_available' => (bool) $item->is_available,
                        ])->values(),
                    ])
                    ->values(),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Advertisements
    |--------------------------------------------------------------------------
    */

    /**
     * Filters: status, payment_status, package_id, restaurant_id, search, page, per_page
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'cancellation_requested', ...self::LIST_STATUSES])],
            'payment_status' => ['nullable', Rule::in(['all', 'pending', 'paid', 'failed', 'refunded'])],
            'package_id' => ['nullable', 'integer'],
            'restaurant_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $base = RestaurantAdvertisement::query()
            ->when($validated['payment_status'] ?? null, fn ($q, $status) => $status === 'all' ? $q : $q->where('payment_status', $status))
            ->when($validated['package_id'] ?? null, fn ($q, $id) => $q->where('package_id', $id))
            ->when($validated['restaurant_id'] ?? null, fn ($q, $id) => $q->where('restaurant_id', $id))
            ->when(trim((string) ($validated['search'] ?? '')), fn ($q, $search) => $this->applySearch($q, $search));

        // Tab counts follow every filter except the status itself
        $counts = ['all' => (clone $base)->count()];
        foreach ([...self::LIST_STATUSES, 'cancellation_requested'] as $status) {
            $counts[$status] = $this->applyStatus(clone $base, $status)->count();
        }

        $status = $validated['status'] ?? 'all';

        $paginator = $this->applyStatus(clone $base, $status)
            ->with(['package', 'foodItem.category', 'restaurant.address'])
            ->latest('id')
            ->paginate($validated['per_page'] ?? 10);

        $admins = $this->adminsFor(collect($paginator->items()));
        $stats = $this->service->eventStats(collect($paginator->items())->pluck('id'));

        return response()->json([
            'success' => true,
            'message' => 'Advertisements fetched successfully.',
            'data' => [
                'counts' => $counts,
                'advertisements' => collect($paginator->items())
                    ->map(fn ($advertisement) => $this->formatAdvertisement($advertisement, $admins)
                        + ['stats' => $stats->get($advertisement->id)])
                    ->values(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
        ]);
    }

    public function show($id)
    {
        return $this->detailResponse($this->service->findForAdmin((int) $id), 'Advertisement fetched successfully.');
    }

    /**
     * Create for the chosen restaurant. Only restaurant_id, package_id,
     * package_duration_id, food_item_id and image are read; price, target,
     * statuses and dates are decided by the service.
     *
     * payment_mode=online: ad waits as pending until the gateway confirms.
     * payment_mode=manual: restaurant already paid; recorded straight away
     * (needs the Approve permission).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' => ['required', 'integer'],
            'package_id' => ['required', 'integer'],
            'package_duration_id' => ['required', 'integer'],
            'food_item_id' => ['nullable'],
            'payment_mode' => ['nullable', Rule::in(['online'])],
        ]);

        $admin = $request->user();
        $restaurant = Restaurant::query()->find((int) $validated['restaurant_id']);

        if (!$restaurant) {
            throw AdvertisementException::invalid('restaurant_id', 'Selected restaurant does not exist.');
        }

        $actor = $this->service->adminActor($admin);

        $advertisement = $this->service->create(
            $restaurant,
            $request->only(['package_id', 'package_duration_id', 'food_item_id']),
            $request->file('image'),
            $actor['type'],
            $actor['id'],
            RestaurantAdvertisement::STATUS_PENDING,
            $actor['name']
        );

        // Auto-initialize PayU payment session so the link is immediately valid
        try {
            $this->service->startPayment($advertisement, $actor);
        } catch (\Throwable $e) {
            // Keep pending even if gateway temporarily throws an error
        }

        $paymentLink = route('advertisement.pay', ['id' => $advertisement->id]);

        return response()->json([
            'success' => true,
            'message' => 'Advertisement created. PayU payment link generated.',
            'data' => [
                'advertisement' => $this->formatDetail($this->service->findForAdmin($advertisement->id)),
                'payment_link' => $paymentLink,
            ],
        ], 201);
    }

    /**
     * Edit an unpaid draft / pending ad (package, duration, food item, image).
     * Sent as multipart POST with _method=PUT when an image is attached.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'package_id' => ['nullable', 'integer'],
            'package_duration_id' => ['nullable', 'integer'],
            'food_item_id' => ['nullable'],
        ]);

        $advertisement = $this->service->findForAdmin((int) $id);

        $input = array_filter(
            $request->only(['package_id', 'package_duration_id']),
            fn ($value) => $value !== null && $value !== ''
        );

        if ($request->exists('food_item_id')) {
            $input['food_item_id'] = $request->input('food_item_id');
        }

        $advertisement = $this->service->updateUnpaid(
            $advertisement,
            $input,
            $request->file('image'),
            $this->service->adminActor($request->user())
        );

        return $this->detailResponse($advertisement, 'Advertisement updated.');
    }

    /**
     * Opens a gateway order for the admin panel checkout. While online
     * payment is switched off the ad goes live as pay later instead.
     */
    public function payment(Request $request, $id)
    {
        $advertisement = $this->service->findForAdmin((int) $id);

        $result = $this->service->startPayment($advertisement, $this->service->adminActor($request->user()));
        $paymentRequired = $result['checkout'] !== null;

        return response()->json([
            'success' => true,
            'message' => $paymentRequired
                ? 'Payment order created.'
                : 'Advertisement is now active. Payment will be collected later.',
            'data' => [
                'payment_required' => $paymentRequired,
                'checkout' => $result['checkout'],
                'advertisement' => $this->formatDetail($this->service->findForAdmin($advertisement->id)),
            ],
        ]);
    }

    public function verifyPayment(Request $request, $id)
    {
        $orderId = $request->input('order_id')
            ?? $request->input('txnid');
        $paymentId = $request->input('payment_id')
            ?? $request->input('mihpayid')
            ?? $orderId;
        $signature = $request->input('signature')
            ?? $request->input('hash')
            ?? '';

        if (empty($orderId)) {
            return response()->json([
                'success' => false,
                'message' => 'The order ID / transaction ID is required.',
            ], 422);
        }

        $advertisement = $this->service->verifyPaymentForAdvertisement(
            $this->service->findForAdmin((int) $id),
            (string) $orderId,
            (string) $paymentId,
            (string) $signature
        );

        $paid = $advertisement->payment_status === RestaurantAdvertisement::PAYMENT_PAID;
        $failed = $advertisement->payment_status === RestaurantAdvertisement::PAYMENT_FAILED;

        return response()->json([
            'success' => $paid,
            'message' => $paid
                ? 'Payment successful. The advertisement is now active.'
                : ($failed ? 'Payment failed. Please try again.' : 'Payment is being processed. The status will update automatically.'),
            'data' => [
                'advertisement' => $this->formatDetail($this->service->findForAdmin($advertisement->id)),
            ],
        ], $failed ? 402 : 200);
    }

    public function activate(Request $request, $id)
    {
        $advertisement = $this->service->activateByAdmin(
            $this->service->findForAdmin((int) $id),
            $this->service->adminActor($request->user())
        );

        return $this->detailResponse($this->service->findForAdmin($advertisement->id), 'Advertisement activated.');
    }

    public function pause(Request $request, $id)
    {
        $advertisement = $this->service->pause(
            $this->service->findForAdmin((int) $id),
            $this->service->adminActor($request->user())
        );

        return $this->detailResponse($this->service->findForAdmin($advertisement->id), 'Advertisement paused. It is hidden from customers.');
    }

    public function resume(Request $request, $id)
    {
        $advertisement = $this->service->resume(
            $this->service->findForAdmin((int) $id),
            $this->service->adminActor($request->user())
        );

        $message = $advertisement->status === RestaurantAdvertisement::STATUS_ACTIVE
            ? 'Advertisement resumed.'
            : 'The paid period ended while paused, so the advertisement is now expired.';

        return $this->detailResponse($this->service->findForAdmin($advertisement->id), $message);
    }

    public function cancel(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $actor = $this->service->adminActor($request->user());

        $advertisement = $this->service->cancelByAdmin(
            $this->service->findForAdmin((int) $id),
            $actor['type'],
            $actor['id'],
            $validated['reason'] ?? null,
            $actor['name']
        );

        return $this->detailResponse(
            $this->service->findForAdmin($advertisement->id),
            $advertisement->payment_status === RestaurantAdvertisement::PAYMENT_PAID
                ? 'Advertisement cancelled. Use Mark Refunded once the refund is done.'
                : 'Advertisement cancelled.'
        );
    }

    /**
     * Restaurant already paid VegGo outside the gateway.
     */
    public function markPaid(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Manual payment override is disabled. All payments must be processed via PayU payment link.',
        ], 403);
    }

    /**
     * Records a refund already done at the gateway / offline.
     */
    public function markRefunded(Request $request, $id)
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $actor = $this->service->adminActor($request->user());

        $advertisement = $this->service->markRefunded(
            $this->service->findForAdmin((int) $id),
            $actor['type'],
            $actor['id'],
            $validated['note'] ?? null,
            $actor['name']
        );

        return $this->detailResponse($this->service->findForAdmin($advertisement->id), 'Refund recorded.');
    }

    /*
    |--------------------------------------------------------------------------
    | Packages & pricing
    |--------------------------------------------------------------------------
    */

    public function packages()
    {
        return response()->json([
            'success' => true,
            'message' => 'Advertisement packages fetched successfully.',
            'data' => [
                'gst_percentage' => (float) config('advertisement.gst_percentage', 18),
                'packages' => $this->service->allPackages()
                    ->map(fn ($package) => $this->formatPackage($package, true))
                    ->values(),
            ],
        ]);
    }

    public function package($id)
    {
        $package = $this->findPackage((int) $id);
        $package->load(['durations' => fn ($q) => $q->orderBy('duration_days')]);

        return response()->json([
            'success' => true,
            'message' => 'Advertisement package fetched successfully.',
            'data' => [
                'gst_percentage' => (float) config('advertisement.gst_percentage', 18),
                'package' => $this->formatPackage($package, true),
            ],
        ]);
    }

    public function updatePackage(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $package = $this->service->updatePackage($this->findPackage((int) $id), $validated);

        return response()->json([
            'success' => true,
            'message' => 'Package updated.',
            'data' => ['package' => $this->formatPackage($package, true)],
        ]);
    }

    public function storeDuration(Request $request, $id)
    {
        $validated = $request->validate([
            'duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'price' => ['required', 'numeric', 'min:1', 'max:9999999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $package = $this->findPackage((int) $id);

        $this->service->saveDuration(
            $package,
            (int) $validated['duration_days'],
            (float) $validated['price'],
            $validated['is_active'] ?? true
        );

        $package->load(['durations' => fn ($q) => $q->orderBy('duration_days')]);

        return response()->json([
            'success' => true,
            'message' => 'Duration saved.',
            'data' => ['package' => $this->formatPackage($package, true)],
        ], 201);
    }

    public function updateDuration(Request $request, $id)
    {
        $validated = $request->validate([
            'price' => ['sometimes', 'required', 'numeric', 'min:1', 'max:9999999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $duration = RestaurantAdPackageDuration::query()->find((int) $id);

        if (!$duration) {
            throw AdvertisementException::notFound('Package duration not found.');
        }

        $this->service->updateDuration($duration, $validated);

        $package = $duration->package()->with(['durations' => fn ($q) => $q->orderBy('duration_days')])->first();

        return response()->json([
            'success' => true,
            'message' => 'Duration updated.',
            'data' => ['package' => $this->formatPackage($package, true)],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function findPackage(int $id): RestaurantAdPackage
    {
        $package = RestaurantAdPackage::query()->find($id);

        if (!$package) {
            throw AdvertisementException::notFound('Advertisement package not found.');
        }

        return $package;
    }

    private function ensureCan(Admin $admin, string $action, string $message): void
    {
        if (!$admin->hasPermission('marketing', $action)) {
            throw AdvertisementException::forbidden($message);
        }
    }

    /**
     * Checked before creating so a taken reference does not leave a
     * half-made ad behind.
     */
    private function ensureReferenceFree(?string $reference): void
    {
        $reference = trim((string) $reference);

        if ($reference === '') {
            return;
        }

        $used = RestaurantAdvertisement::query()->where('payment_reference', $reference)->exists()
            || RestaurantAdPayment::query()->where('gateway_payment_id', $reference)->exists();

        if ($used) {
            throw AdvertisementException::invalid('payment_reference', 'This payment reference is already used.');
        }
    }

    private function paymentConfig(): array
    {
        $onlineEnabled = (bool) config('advertisement.payment_enabled', true);
        $gateway = config('advertisement.payment_driver', 'payu');

        return [
            'online_enabled' => $onlineEnabled,
            'gateway' => $gateway,
            'key' => $onlineEnabled && $gateway === 'payu' ? config('services.payu.key') : null,
            'key_id' => $onlineEnabled && $gateway === 'payu' ? config('services.payu.key') : null,
        ];
    }

    private function applyStatus(Builder $query, string $status): Builder
    {
        $now = now();

        return match ($status) {
            'active' => $query->where('status', RestaurantAdvertisement::STATUS_ACTIVE)
                ->where('start_at', '<=', $now)
                ->where('end_at', '>', $now),
            'scheduled' => $query->where('status', RestaurantAdvertisement::STATUS_ACTIVE)
                ->where('start_at', '>', $now),
            'paused' => $query->where('status', RestaurantAdvertisement::STATUS_PAUSED)
                ->where('end_at', '>', $now),
            // Past end_at counts as expired even before the scheduler has run
            'expired' => $query->where(fn ($q) => $q
                ->where('status', RestaurantAdvertisement::STATUS_EXPIRED)
                ->orWhere(fn ($q) => $q
                    ->whereIn('status', [RestaurantAdvertisement::STATUS_ACTIVE, RestaurantAdvertisement::STATUS_PAUSED])
                    ->where('end_at', '<=', $now))),
            'cancellation_requested' => $query->whereNotNull('cancellation_requested_at')
                ->whereIn('status', [RestaurantAdvertisement::STATUS_ACTIVE, RestaurantAdvertisement::STATUS_PAUSED]),
            'draft', 'pending', 'cancelled' => $query->where('status', $status),
            default => $query,
        };
    }

    /**
     * Ad id (12 or AD-00012), restaurant name or promoted food item name.
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        $id = preg_match('/^(?:AD-?)?0*(\d+)$/i', $search, $match) ? (int) $match[1] : null;

        return $query->where(function ($q) use ($search, $id) {
            if ($id) {
                $q->orWhere('id', $id);
            }

            $q->orWhereHas('restaurant', fn ($r) => $r->where('restaurant_name', 'like', '%' . $search . '%'))
                ->orWhere(fn ($f) => $f
                    ->where('target_type', RestaurantAdvertisement::TARGET_FOOD_ITEM)
                    ->whereHas('foodItem', fn ($i) => $i->where('name', 'like', '%' . $search . '%')));
        });
    }

    /**
     * Admins referenced as creator / payer / canceller, keyed by id.
     */
    private function adminsFor(Collection $advertisements): Collection
    {
        $ids = $advertisements
            ->flatMap(fn ($advertisement) => [
                $this->isAdminType($advertisement->created_by_type) ? $advertisement->created_by_id : null,
                $advertisement->paid_by_admin_id,
                $this->isAdminType($advertisement->cancelled_by_type) ? $advertisement->cancelled_by_id : null,
            ])
            ->filter()
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? collect()
            : Admin::query()->whereIn('id', $ids)->get(['id', 'name', 'email', 'role'])->keyBy('id');
    }

    private function isAdminType(?string $type): bool
    {
        return in_array($type, [RestaurantAdvertisement::CREATED_BY_ADMIN, RestaurantAdvertisement::CREATED_BY_SUPER_ADMIN], true);
    }

    private function person(?string $type, ?int $id, RestaurantAdvertisement $advertisement, Collection $admins): ?array
    {
        if (!$type) {
            return null;
        }

        $name = match (true) {
            $type === RestaurantAdvertisement::CREATED_BY_RESTAURANT => $advertisement->restaurant?->restaurant_name,
            $this->isAdminType($type) => $admins->get($id)?->name,
            default => null,
        };

        $label = match ($type) {
            RestaurantAdvertisement::CREATED_BY_SUPER_ADMIN => 'Super Admin',
            RestaurantAdvertisement::CREATED_BY_ADMIN => 'Admin',
            RestaurantAdvertisement::CREATED_BY_RESTAURANT => 'Restaurant',
            default => ucfirst(str_replace('_', ' ', $type)),
        };

        return [
            'type' => $type,
            'type_label' => $label,
            'id' => $id,
            'name' => $name,
            'display' => $name ? "{$label} · {$name}" : "{$label} #{$id}",
        ];
    }

    private function detailResponse(RestaurantAdvertisement $advertisement, string $message, int $status = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['advertisement' => $this->formatDetail($advertisement)],
        ], $status);
    }

    private function formatDetail(RestaurantAdvertisement $advertisement): array
    {
        $advertisement->loadMissing(['package', 'foodItem.category', 'restaurant.address', 'payments']);
        $histories = $advertisement->histories()->latest('id')->get();

        $data = $this->formatAdvertisement($advertisement, $this->adminsFor(collect([$advertisement])));

        $data['stats'] = $this->service->eventStats(collect([$advertisement->id]))->get($advertisement->id);

        $data['payments'] = $advertisement->payments
            ->sortByDesc('id')
            ->map(fn ($payment) => [
                'id' => $payment->id,
                'gateway' => $payment->gateway,
                'order_id' => $payment->gateway_order_id,
                'payment_id' => $payment->gateway_payment_id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'verified_via' => $payment->verified_via,
                'failure_reason' => $payment->failure_reason,
                'paid_at' => $payment->paid_at,
                'created_at' => $payment->created_at,
            ])
            ->values();

        $data['history'] = $histories->map(fn ($row) => [
            'id' => $row->id,
            'action' => $row->action,
            'from_status' => $row->from_status,
            'to_status' => $row->to_status,
            'payment_status' => $row->payment_status,
            'actor_type' => $row->actor_type,
            'actor_id' => $row->actor_id,
            'actor_name' => $row->actor_name,
            'note' => $row->note,
            'meta' => $row->meta,
            'created_at' => $row->created_at,
        ])->values();

        return $data;
    }

    private function formatAdvertisement(RestaurantAdvertisement $advertisement, Collection $admins): array
    {
        $restaurant = $advertisement->restaurant;
        $restaurantImage = $this->publicUrl($restaurant?->address?->restaurant_front_image);
        $displayStatus = $advertisement->displayStatus();

        $target = match ($advertisement->target_type) {
            RestaurantAdvertisement::TARGET_FOOD_ITEM => [
                'type' => 'food_item',
                'label' => 'Food Item',
                'id' => $advertisement->target_id,
                'name' => $advertisement->foodItem?->name,
                'category' => $advertisement->foodItem?->category?->name,
                'image_url' => $this->publicUrl($advertisement->foodItem?->image),
            ],
            RestaurantAdvertisement::TARGET_RESTAURANT => [
                'type' => 'restaurant',
                'label' => 'Featured Listing',
                'id' => $advertisement->target_id,
                'name' => $restaurant?->restaurant_name,
            ],
            RestaurantAdvertisement::TARGET_HOME => ['type' => 'home', 'label' => 'Customer Home Screen', 'id' => null, 'name' => 'Customer Home Screen'],
            RestaurantAdvertisement::TARGET_CHECKOUT => ['type' => 'checkout', 'label' => 'Checkout Page', 'id' => null, 'name' => 'Checkout of ' . ($restaurant?->restaurant_name ?? 'restaurant')],
            default => ['type' => $advertisement->target_type, 'label' => $advertisement->target_type, 'id' => $advertisement->target_id, 'name' => null],
        };

        $unpaidOpen = in_array($advertisement->status, [RestaurantAdvertisement::STATUS_DRAFT, RestaurantAdvertisement::STATUS_PENDING], true)
            && $advertisement->payment_status !== RestaurantAdvertisement::PAYMENT_PAID;
        $notEnded = !$advertisement->end_at || $advertisement->end_at->gt(now());

        return [
            'id' => $advertisement->id,
            'reference' => 'AD-' . str_pad((string) $advertisement->id, 5, '0', STR_PAD_LEFT),
            'restaurant' => [
                'id' => $restaurant?->id,
                'name' => $restaurant?->restaurant_name,
                'status' => $restaurant?->status,
                'is_online' => (bool) $restaurant?->is_online,
                'image_url' => $restaurantImage,
            ],
            'package' => [
                'id' => $advertisement->package?->id,
                'name' => $advertisement->package?->name,
                'code' => $advertisement->package?->code,
                'placement' => $advertisement->package?->placement,
                'requires_image' => (bool) $advertisement->package?->requires_image,
            ],
            'package_duration_id' => $advertisement->package_duration_id,
            'target' => $target,
            'image' => $advertisement->image,
            // Featured Restaurant has no ad image: the restaurant card image is shown
            'image_url' => $advertisement->imageUrl()
                ?? ($advertisement->target_type === RestaurantAdvertisement::TARGET_RESTAURANT ? $restaurantImage : null),
            'duration_days' => $advertisement->duration_days,
            'amount' => $advertisement->amount,
            'tax_percentage' => $advertisement->tax_percentage,
            'tax_amount' => $advertisement->tax_amount,
            'total_amount' => $advertisement->total_amount,
            'payment_status' => $advertisement->payment_status,
            'payment_method' => $advertisement->payment_method,
            'payment_reference' => $advertisement->payment_reference,
            'status' => $displayStatus,
            'raw_status' => $advertisement->status,
            'is_live' => $displayStatus === RestaurantAdvertisement::STATUS_ACTIVE
                && in_array($advertisement->payment_status, RestaurantAdvertisement::livePaymentStatuses(), true)
                && $restaurant?->status === 'approved',
            'start_at' => $advertisement->start_at,
            'end_at' => $advertisement->end_at,
            'paid_at' => $advertisement->paid_at,
            'created_by' => $this->person($advertisement->created_by_type, $advertisement->created_by_id, $advertisement, $admins),
            'paid_by' => $advertisement->paid_by_admin_id
                ? [
                    'id' => $advertisement->paid_by_admin_id,
                    'name' => $admins->get($advertisement->paid_by_admin_id)?->name,
                ]
                : null,
            'cancelled_by' => $this->person($advertisement->cancelled_by_type, $advertisement->cancelled_by_id, $advertisement, $admins),
            'cancellation_requested_at' => $advertisement->cancellation_requested_at,
            'cancellation_reason' => $advertisement->cancellation_reason,
            'cancelled_at' => $advertisement->cancelled_at,
            'refunded_at' => $advertisement->refunded_at,
            'payment_link' => route('advertisement.pay', ['id' => $advertisement->id]),
            'created_at' => $advertisement->created_at,
            'updated_at' => $advertisement->updated_at,
            // What the ad's state allows; the panel also checks the admin's permissions
            'actions' => [
                'edit' => $unpaidOpen && $advertisement->payment_status !== RestaurantAdvertisement::PAYMENT_REFUNDED,
                'collect_payment' => $unpaidOpen,
                'mark_paid' => false,
                'copy_payment_link' => $unpaidOpen,
                'activate' => $unpaidOpen
                    ? !config('advertisement.payment_enabled', true)
                    : in_array($advertisement->status, [RestaurantAdvertisement::STATUS_DRAFT, RestaurantAdvertisement::STATUS_PENDING], true),
                'pause' => $advertisement->status === RestaurantAdvertisement::STATUS_ACTIVE && $notEnded,
                'resume' => $advertisement->status === RestaurantAdvertisement::STATUS_PAUSED,
                'cancel' => !in_array($advertisement->status, [RestaurantAdvertisement::STATUS_CANCELLED, RestaurantAdvertisement::STATUS_EXPIRED], true)
                    && $displayStatus !== RestaurantAdvertisement::STATUS_EXPIRED,
                'refund' => $advertisement->payment_status === RestaurantAdvertisement::PAYMENT_PAID,
            ],
        ];
    }

    private function formatPackage(RestaurantAdPackage $package, bool $allDurations): array
    {
        $durations = $allDurations ? $package->durations : $package->activeDurations;

        return [
            'id' => $package->id,
            'name' => $package->name,
            'code' => $package->code,
            'description' => $package->description,
            'placement' => $package->placement,
            'requires_image' => (bool) $package->requires_image,
            'requires_food_item' => $package->placement === RestaurantAdvertisement::TARGET_FOOD_ITEM,
            'is_active' => (bool) $package->is_active,
            'durations' => $durations->map(function ($duration) {
                $price = $this->service->calculatePrice($duration);

                return [
                    'id' => $duration->id,
                    'duration_days' => $duration->duration_days,
                    'price' => $price['amount'],
                    'tax_amount' => $price['tax_amount'],
                    'total_amount' => $price['total_amount'],
                    'is_active' => (bool) $duration->is_active,
                ];
            })->values(),
        ];
    }

    private function publicUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return preg_match('#^https?://#i', $path) ? $path : Storage::disk('public')->url($path);
    }
}
