<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\SuperAdmin\Concerns\ResolvesRestaurantLocation;
use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Models\RestaurantDocument;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOutletType;
use App\Models\RestaurantWorkingDay;
use App\Models\RestaurantWorkingHour;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SuperAdminRestaurantController extends Controller
{
    /**
     * Indian mobile accepted by the restaurant app's OTP login
     */
    private const MOBILE_REGEX = '/^[6-9]\d{9}$/';

    private const MOBILE_MESSAGES = [
        'mobile.regex' => 'Please enter a valid 10 digit mobile number starting with 6, 7, 8 or 9.',
        'mobile.unique' => 'A restaurant is already registered with this mobile number.',
    ];

    use ResolvesRestaurantLocation;

    /**
     * Max rows returned by the CSV export
     */
    private const EXPORT_LIMIT = 5000;

    /**
     * Page status filter -> restaurants.status values
     */
    private const STATUS_FILTERS = [
        'active' => ['approved'],
        'onboarding' => ['draft', 'pending_approval'],
        'suspended' => ['suspended'],
        'rejected' => ['rejected'],
        'inactive' => ['inactive'],
    ];

    private const FILTER_RULES = [
        'search' => ['nullable', 'string', 'max:100'],
        'status' => ['nullable', 'in:active,onboarding,suspended,rejected,inactive'],
        'verification' => ['nullable', 'in:verified,in_review,pending,rejected,not_submitted'],
        'city_id' => ['nullable', 'integer'],
        'area' => ['nullable', 'string', 'max:100'],
        'rating' => ['nullable', 'in:4,3'],
    ];

    /**
     * Restaurant list (search + filters + pagination), header count and filter options
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            ...self::FILTER_RULES,
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $restaurants = $this->filteredQuery($validated)
            ->orderByDesc('restaurants.id')
            ->paginate($validated['per_page'] ?? 10);

        $lookups = $this->locationLookups();

        $restaurants->getCollection()->transform(fn ($restaurant) => $this->listRow($restaurant, $lookups));

        return response()->json([
            'message' => 'Restaurants fetched successfully.',
            'data' => $restaurants,
            'stats' => [
                'total' => Restaurant::count(),
            ],
            'filters' => [
                'cities' => $lookups['cities']->map(fn ($city) => [
                    'id' => $city->id,
                    'name' => $city->name,
                ])->values(),
                'areas' => $lookups['areas']->map(fn ($area) => [
                    'id' => $area->id,
                    'city_id' => $area->city_id,
                    'name' => $area->name,
                ])->values(),
            ],
        ]);
    }

    /**
     * All filtered restaurants (no pagination) for the CSV export
     */
    public function export(Request $request)
    {
        $validated = $request->validate(self::FILTER_RULES);

        $lookups = $this->locationLookups();

        $restaurants = $this->filteredQuery($validated)
            ->orderByDesc('restaurants.id')
            ->limit(self::EXPORT_LIMIT)
            ->get()
            ->map(fn ($restaurant) => $this->listRow($restaurant, $lookups));

        return response()->json([
            'message' => 'Restaurants exported successfully.',
            'data' => $restaurants,
        ]);
    }

    /**
     * Full restaurant profile: stats, details, documents, change history
     */
    public function show($id)
    {
        $restaurant = $this->filteredQuery([])
            ->where('restaurants.id', $id)
            ->first();

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $restaurant->load([
            'document.outletType:id,name',
            'workingHours' => fn ($query) => $query->orderBy('slot'),
            'workingDays',
        ]);

        $menuItemsCount = RestaurantMenuItem::where('restaurant_id', $restaurant->id)->count();

        $document = $restaurant->document;

        return response()->json([
            'message' => 'Restaurant details fetched successfully.',
            'data' => [
                ...$this->listRow($restaurant, $this->locationLookups()),
                'whatsapp_number' => $restaurant->whatsapp_number,
                'full_address' => $this->fullAddress($restaurant),
                'operating_hours' => $this->operatingHours($restaurant),
                'menu_items_count' => $menuItemsCount,
                'is_online' => (bool) $restaurant->is_online,
                'wallet_balance' => (float) $restaurant->wallet_balance,
                'onboarding_step' => (int) $restaurant->onboarding_step,
                'suspended_at' => $this->iso($restaurant->suspended_at),
                'documents' => [
                    'status' => $document?->status,
                    'outlet_type' => $document?->outletType?->name,
                    'rejection_reason' => $document?->rejection_reason,
                    'reviewed_at' => $this->iso($document?->reviewed_at),
                    'fssai' => $document?->fssai_number,
                    'fssai_image_url' => $this->documentUrl($document?->fssai_image_path),
                    'gst' => $document?->gstin_number,
                    'gst_image_url' => $this->documentUrl($document?->gst_image_path),
                    'pan' => $document?->pan_number,
                    'pan_image_url' => $this->documentUrl($document?->pan_image_path),
                ],
                'history' => $this->history($restaurant),
            ],
        ]);
    }

    /**
     * Create a restaurant account (the owner logs in later with OTP on this
     * mobile and completes onboarding in the restaurant app)
     */
    public function store(Request $request)
    {
        $request->merge(['mobile' => $this->normalizePhone($request->input('mobile'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'owner' => ['required', 'string', 'max:255'],
            // Same rule as the restaurant app's OTP login, so the owner can sign in
            'mobile' => ['required', 'regex:' . self::MOBILE_REGEX, 'unique:restaurants,mobile'],
            'email' => ['required', 'email', 'max:255'],
            'is_whatsapp_same' => ['required', 'boolean'],
            'whatsapp_number' => ['required_if:is_whatsapp_same,0', 'nullable', 'digits:10'],
            'shop_plot_number' => ['required', 'string', 'max:100'],
            'floor' => ['nullable', 'string', 'max:100'],
            'building_name' => ['required', 'string', 'max:255'],
            'full_address' => ['required', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'pincode' => ['required', 'digits:6'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['required', Rule::in([
                'Monday',
                'Tuesday',
                'Wednesday',
                'Thursday',
                'Friday',
                'Saturday',
                'Sunday',
            ])],
            'same_time_all_days' => ['required', 'boolean'],
            'slots' => ['required_if:same_time_all_days,1', 'array', 'min:1', 'max:3'],
            'slots.*.open_time' => ['required_if:same_time_all_days,1', 'date_format:H:i'],
            'slots.*.close_time' => ['required_if:same_time_all_days,1', 'date_format:H:i'],
            'day_slots' => ['required_if:same_time_all_days,0', 'array'],
            'outlet_type' => ['required', 'string', Rule::exists('restaurant_outlet_types', 'code')->where('is_active', true)],
            'pan_number' => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'pan_image' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'gstin_number' => ['nullable', 'required_if:outlet_type,category_1', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'gst_image' => ['nullable', 'required_if:outlet_type,category_1', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'upi_id' => ['required', 'regex:/^[a-zA-Z0-9._-]{2,256}@[a-zA-Z]{2,64}$/'],
            'upi_holder_name' => ['required', 'string', 'max:100'],
            'fssai_number' => ['required', 'digits:14'],
            'fssai_image' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ], self::MOBILE_MESSAGES);

        $workingHours = $this->validatedWorkingHours($validated);
        $uploadedPaths = [];

        try {
            foreach (['pan_image', 'gst_image', 'fssai_image'] as $field) {
                if ($request->hasFile($field)) {
                    $path = $request->file($field)->store('restaurants/documents', 'public');
                    if ($path === false) {
                        throw new \RuntimeException("Unable to store {$field}.");
                    }
                    $uploadedPaths[$field] = $path;
                }
            }

            $restaurant = DB::transaction(function () use ($validated, $uploadedPaths, $workingHours) {
                $restaurant = Restaurant::create([
                    'restaurant_name' => trim($validated['name']),
                    'owner_name' => trim($validated['owner']),
                    'mobile' => $validated['mobile'],
                    'email' => $this->normalizeEmail($validated['email']),
                    'whatsapp_number' => $validated['is_whatsapp_same']
                        ? $validated['mobile']
                        : $validated['whatsapp_number'],
                    'is_whatsapp_same' => (bool) $validated['is_whatsapp_same'],
                    'same_time_all_days' => (bool) $validated['same_time_all_days'],
                    'status' => 'draft',
                    'onboarding_step' => 7,
                    'is_profile_completed' => false,
                ]);

                $restaurant->address()->create([
                    'shop_plot_number' => trim($validated['shop_plot_number']),
                    'floor' => $validated['floor'] ?? null,
                    'building_name' => trim($validated['building_name']),
                    'full_address' => trim($validated['full_address']),
                    'pincode' => $validated['pincode'],
                    'landmark' => $validated['landmark'] ?? null,
                ]);

                foreach ($validated['days'] as $day) {
                    RestaurantWorkingDay::create([
                        'restaurant_id' => $restaurant->id,
                        'day' => $day,
                    ]);
                }

                foreach ($workingHours as $day => $slots) {
                    foreach ($slots as $index => $slot) {
                        RestaurantWorkingHour::create([
                            'restaurant_id' => $restaurant->id,
                            'day' => $day,
                            'slot' => $index + 1,
                            'open_time' => $slot['open_time'],
                            'close_time' => $slot['close_time'],
                        ]);
                    }
                }

                $outletType = RestaurantOutletType::where('code', $validated['outlet_type'])
                    ->where('is_active', true)
                    ->firstOrFail();

                $restaurant->document()->create([
                    'outlet_type_id' => $outletType->id,
                    'pan_number' => $validated['pan_number'],
                    'pan_image_path' => $uploadedPaths['pan_image'],
                    'gstin_number' => $validated['gstin_number'] ?? null,
                    'gst_image_path' => $uploadedPaths['gst_image'] ?? null,
                    'upi_id' => $validated['upi_id'],
                    'upi_holder_name' => trim($validated['upi_holder_name']),
                    'fssai_number' => $validated['fssai_number'],
                    'fssai_image_path' => $uploadedPaths['fssai_image'],
                    'status' => 'pending',
                ]);

                return $restaurant;
            });
        } catch (\Throwable $exception) {
            if ($uploadedPaths) {
                Storage::disk('public')->delete(array_values($uploadedPaths));
            }

            throw $exception;
        }

        $this->log($request, $restaurant, 'Restaurant Created', 'Restaurant account created by super admin');

        return response()->json([
            'message' => 'Restaurant created successfully.',
            'data' => $this->freshRow($restaurant->id),
        ], 201);
    }

    private function validatedWorkingHours(array $validated): array
    {
        $days = $validated['days'];
        $sameTime = (bool) $validated['same_time_all_days'];
        $daySlots = $sameTime
            ? array_fill_keys($days, $validated['slots'])
            : array_intersect_key($validated['day_slots'] ?? [], array_flip($days));

        $workingHours = [];

        foreach ($days as $day) {
            $slots = $daySlots[$day] ?? [];
            if (!$slots || count($slots) > 3) {
                abort(422, "Add at least one valid time slot for {$day}.");
            }

            foreach ($slots as $slot) {
                if (
                    empty($slot['open_time'])
                    || empty($slot['close_time'])
                    || !preg_match('/^\d{2}:\d{2}$/', $slot['open_time'])
                    || !preg_match('/^\d{2}:\d{2}$/', $slot['close_time'])
                ) {
                    abort(422, "Enter both opening and closing times for {$day}.");
                }
            }

            $workingHours[$day] = $slots;
        }

        return $workingHours;
    }

    /**
     * Update restaurant basic details
     */
    public function update(Request $request, $id)
    {
        $restaurant = Restaurant::find($id);

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $request->merge(['mobile' => $this->normalizePhone($request->input('mobile'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'owner' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'regex:' . self::MOBILE_REGEX, Rule::unique('restaurants', 'mobile')->ignore($restaurant->id)],
            'email' => ['nullable', 'email', 'max:255'],
        ], self::MOBILE_MESSAGES);

        $values = [
            'restaurant_name' => trim($validated['name']),
            'owner_name' => trim($validated['owner']),
            'mobile' => $validated['mobile'],
            'email' => $this->normalizeEmail($validated['email'] ?? null),
        ];

        $labels = [
            'restaurant_name' => 'Name',
            'owner_name' => 'Owner',
            'mobile' => 'Mobile',
            'email' => 'Email',
        ];

        $changes = collect($values)
            ->filter(fn ($value, $column) => (string) $restaurant->{$column} !== (string) $value)
            ->map(fn ($value, $column) => $labels[$column] . ': ' . ($restaurant->{$column} ?: '—') . ' → ' . ($value ?: '—'));

        DB::transaction(function () use ($restaurant, $values) {
            // A new mobile number has to be verified again by OTP
            if ($restaurant->mobile !== $values['mobile']) {
                $restaurant->tokens()->delete();
            }

            $restaurant->update($values);
        });

        if ($changes->isNotEmpty()) {
            $this->log($request, $restaurant, 'Restaurant Updated', $changes->implode('; '));
        }

        return response()->json([
            'message' => 'Restaurant updated successfully.',
            'data' => $this->freshRow($restaurant->id),
        ]);
    }

    /**
     * Suspend a restaurant, or lift its suspension
     */
    public function updateStatus(Request $request, $id)
    {
        $restaurant = Restaurant::find($id);

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:suspended,active'],
        ]);

        if ($validated['status'] === 'suspended') {
            if ($restaurant->status === 'suspended') {
                return response()->json([
                    'message' => 'Restaurant is already suspended.',
                ], 422);
            }

            // Tokens are kept so the app gets the "suspended" response
            // (EnsureRestaurantNotSuspended) instead of a plain 401
            $restaurant->update([
                'status_before_suspension' => $restaurant->status,
                'status' => 'suspended',
                'suspended_at' => now(),
                'is_online' => false,
            ]);

            $this->log($request, $restaurant, 'Restaurant Suspended', 'Status: Active → Suspended', 'High');
        } else {
            if ($restaurant->status !== 'suspended') {
                return response()->json([
                    'message' => 'Restaurant is not suspended.',
                ], 422);
            }

            $restaurant->update([
                'status' => $restaurant->status_before_suspension ?? 'approved',
                'status_before_suspension' => null,
                'suspended_at' => null,
            ]);

            $this->log($request, $restaurant, 'Restaurant Activated', 'Status: Suspended → ' . $this->statusLabel($restaurant->status), 'Medium');
        }

        return response()->json([
            'message' => $validated['status'] === 'suspended'
                ? 'Restaurant suspended successfully.'
                : 'Restaurant suspension lifted successfully.',
            'data' => $this->freshRow($restaurant->id),
        ]);
    }

    /**
     * Restaurant menu items with their category
     */
    public function menu($id)
    {
        if (!Restaurant::whereKey($id)->exists()) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $items = RestaurantMenuItem::query()
            ->where('restaurant_id', $id)
            ->with('category:id,name,display_order')
            ->orderBy('category_id')
            ->orderBy('name')
            ->get()
            ->map(fn ($item) => $this->menuRow($item));

        return response()->json([
            'message' => 'Restaurant menu fetched successfully.',
            'data' => $items->values(),
        ]);
    }

    /**
     * Mark a menu item available / unavailable
     */
    public function toggleMenuItem($id, $itemId)
    {
        $item = RestaurantMenuItem::query()
            ->where('restaurant_id', $id)
            ->with('category:id,name,display_order')
            ->find($itemId);

        if (!$item) {
            return response()->json([
                'message' => 'Menu item not found.',
            ], 404);
        }

        $item->update(['is_available' => !$item->is_available]);

        return response()->json([
            'message' => 'Menu item updated successfully.',
            'data' => $this->menuRow($item),
        ]);
    }

    /**
     * Latest orders received by the restaurant
     */
    public function orders($id)
    {
        if (!Restaurant::whereKey($id)->exists()) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $orders = RestaurantOrder::query()
            ->where('restaurant_id', $id)
            ->with([
                'customer:id,name,phone',
                'items:id,order_id,menu_item_name,quantity',
            ])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer' => $order->customer?->name ?: ($order->customer?->phone ?? '—'),
                'items' => $order->items
                    ->map(fn ($item) => trim(($item->menu_item_name ?: 'Item') . ' × ' . $item->quantity))
                    ->implode(', '),
                'amount' => (float) $order->total_amount,
                'order_status' => $order->order_status,
                'placed_at' => $this->iso($order->placed_at ?? $order->created_at),
            ]);

        return response()->json([
            'message' => 'Restaurant orders fetched successfully.',
            'data' => $orders->values(),
        ]);
    }

    // =====================================================
    // HELPERS
    // =====================================================

    /**
     * Restaurants with address, document status, rating, order totals and
     * the resolved city, filtered by the list filters
     */
    private function filteredQuery(array $filters): Builder
    {
        $defaultRate = (float) config('restaurant.commission_percentage', 30.00);
        $rateSql = "COALESCE(r.commission_percentage, {$defaultRate})";
        $billableSql = "o.order_status != 'cancelled' AND o.earning_status != 'reversed'";

        // Commission is stored once the earning is credited;
        // until then it is what it will be at the current rate
        $commissionSql = "CASE WHEN o.earning_status = 'pending' THEN ROUND(o.subtotal * {$rateSql} / 100, 2) ELSE o.commission_amount END";

        $orderTotals = DB::table('restaurant_orders as o')
            ->join('restaurants as r', 'r.id', '=', 'o.restaurant_id')
            ->groupBy('o.restaurant_id')
            ->select('o.restaurant_id')
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw("SUM(CASE WHEN o.order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders")
            ->selectRaw("SUM(CASE WHEN {$billableSql} THEN o.subtotal ELSE 0 END) as gross_sales")
            ->selectRaw("SUM(CASE WHEN {$billableSql} THEN {$commissionSql} ELSE 0 END) as commission")
            ->selectRaw("SUM(CASE WHEN {$billableSql} AND o.earning_status = 'credited' THEN 1 ELSE 0 END) as credited_orders")
            ->selectRaw("SUM(CASE WHEN {$billableSql} AND o.earning_status = 'pending' THEN 1 ELSE 0 END) as pending_orders")
            ->selectRaw(
                "SUM(CASE WHEN {$billableSql} AND COALESCE(o.placed_at, o.created_at) >= ? THEN 1 ELSE 0 END) as orders_30d",
                [now()->subDays(30)]
            );

        $documentStatusSql = '(SELECT rd.status FROM restaurant_documents rd WHERE rd.restaurant_id = restaurants.id ORDER BY rd.id DESC LIMIT 1)';
        $ratingSql = '(SELECT AVG(rr.rating) FROM restaurant_reviews rr WHERE rr.restaurant_id = restaurants.id)';
        $citySql = $this->citySql();

        $search = trim((string) ($filters['search'] ?? ''));

        return Restaurant::query()
            ->leftJoin('restaurant_addresses as ra', 'ra.restaurant_id', '=', 'restaurants.id')
            ->leftJoinSub($orderTotals, 'ot', 'ot.restaurant_id', '=', 'restaurants.id')
            ->select('restaurants.*')
            ->addSelect([
                'ra.full_address',
                'ra.shop_plot_number',
                'ra.floor',
                'ra.building_name',
                'ra.complete_address',
                'ra.landmark',
                'ra.pincode',
                'ot.total_orders',
                'ot.cancelled_orders',
                'ot.gross_sales',
                'ot.commission',
                'ot.credited_orders',
                'ot.pending_orders',
                'ot.orders_30d',
            ])
            ->selectRaw("{$documentStatusSql} as document_status")
            ->selectRaw("{$ratingSql} as rating_avg")
            ->selectRaw('(SELECT COUNT(*) FROM restaurant_reviews rr WHERE rr.restaurant_id = restaurants.id) as reviews_count')
            ->selectRaw("{$citySql} as city_id")
            ->with(['categories' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('display_order')
                ->select('id', 'restaurant_id', 'name', 'display_order')])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $like = '%' . $search . '%';
                    $digits = preg_replace('/\D/', '', $search);

                    $query->where('restaurants.restaurant_name', 'like', $like)
                        ->orWhere('restaurants.owner_name', 'like', $like)
                        ->orWhere('restaurants.email', 'like', $like);

                    if ($digits !== '') {
                        // "+91 98765 43210" -> "9876543210"
                        $phoneDigits = strlen($digits) > 10 ? substr($digits, -10) : $digits;
                        $query->orWhere('restaurants.mobile', 'like', '%' . $phoneDigits . '%');
                    }

                    // "#VG-0012" / "VG-12" / "12"
                    if (preg_match('/^#?(?:vg-?)?0*(\d+)$/i', $search, $match)) {
                        $query->orWhere('restaurants.id', (int) $match[1]);
                    }
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('restaurants.status', self::STATUS_FILTERS[$status]))
            ->when($filters['verification'] ?? null, function ($query, $verification) use ($documentStatusSql) {
                if ($verification === 'not_submitted') {
                    $query->whereRaw("{$documentStatusSql} IS NULL");
                    return;
                }

                $query->whereRaw("{$documentStatusSql} = ?", [match ($verification) {
                    'verified' => 'approved',
                    default => $verification,
                }]);
            })
            ->when($filters['city_id'] ?? null, fn ($query, $cityId) => $query->whereRaw("{$citySql} = ?", [$cityId]))
            ->when($filters['area'] ?? null, fn ($query, $area) => $query->whereRaw(
                "CONCAT_WS(' ', ra.full_address, ra.landmark, ra.complete_address) LIKE ?",
                ['%' . $area . '%']
            ))
            ->when($filters['rating'] ?? null, fn ($query, $rating) => $query->whereRaw("{$ratingSql} >= ?", [(int) $rating]));
    }

    private function freshRow(int $id): array
    {
        return $this->listRow(
            $this->filteredQuery([])->where('restaurants.id', $id)->firstOrFail(),
            $this->locationLookups()
        );
    }

    private function listRow(Restaurant $restaurant, array $lookups): array
    {
        $city = $restaurant->city_id ? $lookups['cities']->firstWhere('id', (int) $restaurant->city_id) : null;

        $grossSales = round((float) $restaurant->gross_sales, 2);
        $commission = round((float) $restaurant->commission, 2);
        $totalOrders = (int) $restaurant->total_orders;
        $reviewsCount = (int) $restaurant->reviews_count;

        return [
            'id' => $restaurant->id,
            'restaurant_code' => '#VG-' . str_pad((string) $restaurant->id, 4, '0', STR_PAD_LEFT),
            'name' => $restaurant->restaurant_name,
            'owner' => $restaurant->owner_name,
            'mobile' => $restaurant->mobile,
            'email' => $restaurant->email,
            'categories' => $restaurant->categories->pluck('name')->values(),
            'city' => $city?->name,
            'area' => $this->area($restaurant, $city?->id, $lookups['areas']),
            'pincode' => $restaurant->pincode,
            'rating' => $reviewsCount > 0 ? round((float) $restaurant->rating_avg, 1) : null,
            'reviews_count' => $reviewsCount,
            'orders_30d' => (int) $restaurant->orders_30d,
            'total_orders' => $totalOrders,
            'gross_sales' => $grossSales,
            'commission_rate' => $restaurant->commissionRate(),
            'commission' => $commission,
            'net_earnings' => round($grossSales - $commission, 2),
            'cancellation_rate' => $totalOrders > 0
                ? round(((int) $restaurant->cancelled_orders / $totalOrders) * 100, 1)
                : 0,
            'settlement' => $this->settlement($restaurant),
            'verification' => $restaurant->document_status ?? 'not_submitted',
            'status' => $restaurant->status,
            'created_at' => $this->iso($restaurant->created_at),
        ];
    }

    /**
     * on_hold: suspended | processing: earnings waiting to be credited |
     * cleared: every earning credited | pending_setup: not live yet | no_orders
     */
    private function settlement(Restaurant $restaurant): string
    {
        return match (true) {
            $restaurant->status === 'suspended' => 'on_hold',
            (int) $restaurant->pending_orders > 0 => 'processing',
            (int) $restaurant->credited_orders > 0 => 'cleared',
            $restaurant->status !== 'approved' => 'pending_setup',
            default => 'no_orders',
        };
    }

    /**
     * "10:00 AM - 11:00 PM (Mon-Sun)" from the onboarding timings
     */
    private function operatingHours(Restaurant $restaurant): ?string
    {
        $week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        $days = $restaurant->workingDays->pluck('day')->unique()
            ->sortBy(fn ($day) => array_search($day, $week))
            ->values();

        $slots = fn ($hours) => $hours
            ->sortBy('slot')
            ->map(fn ($hour) => Carbon::parse($hour->open_time)->format('h:i A') . ' - ' . Carbon::parse($hour->close_time)->format('h:i A'))
            ->unique()
            ->implode(', ');

        $byDay = $restaurant->workingHours->groupBy('day');

        if ($byDay->isEmpty()) {
            return null;
        }

        $openDays = $days->isNotEmpty() ? $days : $byDay->keys()->sortBy(fn ($day) => array_search($day, $week))->values();

        $schedules = $openDays
            ->map(fn ($day) => ['day' => $day, 'time' => $slots($byDay->get($day, collect()))])
            ->filter(fn ($row) => $row['time'] !== '');

        if ($schedules->isEmpty()) {
            return null;
        }

        $short = fn ($day) => substr($day, 0, 3);

        // Same timing every open day -> one line
        if ($schedules->pluck('time')->unique()->count() === 1) {
            $label = $schedules->count() === 7
                ? 'Mon-Sun'
                : $schedules->map(fn ($row) => $short($row['day']))->implode(', ');

            return $schedules->first()['time'] . ' (' . $label . ')';
        }

        return $schedules
            ->map(fn ($row) => $short($row['day']) . ': ' . $row['time'])
            ->implode(' | ');
    }

    /**
     * Admin / super admin actions logged against this restaurant
     */
    private function history(Restaurant $restaurant): array
    {
        return AdminActivityLog::query()
            ->where('target', '#RES-' . $restaurant->id)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'admin_name', 'action', 'change_summary', 'details', 'created_at'])
            ->map(fn ($log) => [
                'id' => $log->id,
                'text' => trim(($log->admin_name ? $log->admin_name . ': ' : '') . $log->action
                    . (($log->change_summary ?: $log->details) ? ' — ' . ($log->change_summary ?: $log->details) : '')),
                'time' => $this->iso($log->created_at),
            ])
            ->values()
            ->all();
    }

    private function menuRow(RestaurantMenuItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'category' => $item->category?->name,
            'price' => (float) $item->price,
            'discount_price' => $item->discount_price !== null ? (float) $item->discount_price : null,
            'is_available' => (bool) $item->is_available,
        ];
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'approved' => 'Active',
            'draft', 'pending_approval' => 'Onboarding',
            default => ucfirst((string) $status),
        };
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

    /**
     * "+91 98765-43210" -> "9876543210"
     */
    private function normalizePhone($phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $phone);

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }
}
