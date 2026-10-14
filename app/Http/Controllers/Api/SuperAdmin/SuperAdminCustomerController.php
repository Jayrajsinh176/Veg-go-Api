<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\RestaurantOrder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SuperAdminCustomerController extends Controller
{
    /**
     * Order date used everywhere on this page
     */
    private const ORDER_DATE_SQL = 'COALESCE(restaurant_orders.placed_at, restaurant_orders.created_at)';

    /**
     * Max rows returned by the CSV export
     */
    private const EXPORT_LIMIT = 5000;

    /**
     * Customer list (search + filters + pagination) and header counts
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,blocked'],
            'period' => ['nullable', 'in:7d,30d,year'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $customers = $this->filteredQuery($validated)
            ->latest('customers.id')
            ->paginate($validated['per_page'] ?? 8);

        $customers->getCollection()->transform(fn ($customer) => $this->listRow($customer));

        return response()->json([
            'message' => 'Customers fetched successfully.',
            'data' => $customers,
            'stats' => [
                'total' => Customer::count(),
                'active' => Customer::where('status', 'active')->count(),
                'blocked' => Customer::where('status', 'blocked')->count(),
                'verified' => Customer::whereNotNull('phone_verified_at')->count(),
            ],
        ]);
    }

    /**
     * All filtered customers (no pagination) for the CSV export
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,blocked'],
            'period' => ['nullable', 'in:7d,30d,year'],
        ]);

        $customers = $this->filteredQuery($validated)
            ->latest('customers.id')
            ->limit(self::EXPORT_LIMIT)
            ->get()
            ->map(fn ($customer) => $this->listRow($customer));

        return response()->json([
            'message' => 'Customers exported successfully.',
            'data' => $customers,
        ]);
    }

    /**
     * Full customer profile: stats, addresses, recent orders, activity
     */
    public function show($id)
    {
        $customer = Customer::with(['addresses' => fn ($query) => $query->orderByDesc('is_default')->latest('id')])
            ->find($id);

        if (!$customer) {
            return response()->json([
                'message' => 'Customer not found.',
            ], 404);
        }

        $orderStats = RestaurantOrder::query()
            ->where('customer_id', $customer->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->selectRaw("SUM(CASE WHEN order_status != 'cancelled' THEN total_amount ELSE 0 END) as total_spent")
            ->selectRaw("SUM(CASE WHEN order_status != 'cancelled' THEN 1 ELSE 0 END) as billable")
            ->selectRaw('MAX(' . self::ORDER_DATE_SQL . ') as last_order_at')
            ->first();

        $billable = (int) $orderStats->billable;
        $totalSpent = round((float) $orderStats->total_spent, 2);

        $orders = RestaurantOrder::query()
            ->where('customer_id', $customer->id)
            ->with([
                'restaurant:id,restaurant_name',
                'items:id,order_id,menu_item_name,quantity',
                'deliveryAddress:id,address,landmark,pincode',
            ])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn ($order) => $this->orderRow($order));

        $lastTokenUse = $customer->tokens()->max('last_used_at');

        $lastActive = collect([$lastTokenUse, $orderStats->last_order_at])
            ->filter()
            ->map(fn ($date) => \Carbon\Carbon::parse($date))
            ->max();

        return response()->json([
            'message' => 'Customer details fetched successfully.',
            'data' => [
                ...$this->baseRow($customer),
                'date_of_birth' => $customer->date_of_birth,
                'phone_verified_at' => $customer->phone_verified_at,
                'last_active_at' => $this->iso($lastActive),
                'stats' => [
                    'total_orders' => (int) $orderStats->total,
                    'delivered' => (int) $orderStats->delivered,
                    'cancelled' => (int) $orderStats->cancelled,
                    'total_spent' => $totalSpent,
                    'avg_order_value' => $billable > 0 ? round($totalSpent / $billable, 2) : 0,
                ],
                'addresses' => $customer->addresses->map(fn ($address) => [
                    'id' => $address->id,
                    'label' => $address->label,
                    'receiver_name' => $address->receiver_name,
                    'receiver_phone' => $address->receiver_phone,
                    'address' => $address->address,
                    'landmark' => $address->landmark,
                    'pincode' => $address->pincode,
                    'is_default' => (bool) $address->is_default,
                ])->values(),
                'orders' => $orders->values(),
                'activity' => $this->activity($customer, $orders),
            ],
        ]);
    }

    /**
     * Create a customer (they log in later with OTP on this phone)
     */
    public function store(Request $request)
    {
        $request->merge(['phone' => $this->normalizePhone($request->input('phone'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'digits:10', 'unique:customers,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:customers,email'],
            'address' => ['nullable', 'string', 'max:1000'],
            'pincode' => ['nullable', 'string', 'max:10'],
        ]);

        $customer = DB::transaction(function () use ($validated) {
            $customer = Customer::create([
                'name' => trim($validated['name']),
                'phone' => $validated['phone'],
                'email' => $this->normalizeEmail($validated['email'] ?? null),
                'status' => 'active',
            ]);

            $this->saveDefaultAddress($customer, $validated);

            return $customer;
        });

        return response()->json([
            'message' => 'Customer created successfully.',
            'data' => $this->listRow($this->freshListCustomer($customer->id)),
        ], 201);
    }

    /**
     * Update customer details (and default address)
     */
    public function update(Request $request, $id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'message' => 'Customer not found.',
            ], 404);
        }

        $request->merge(['phone' => $this->normalizePhone($request->input('phone'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'digits:10', Rule::unique('customers', 'phone')->ignore($customer->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($customer->id)],
            'address' => ['nullable', 'string', 'max:1000'],
            'pincode' => ['nullable', 'string', 'max:10'],
        ]);

        DB::transaction(function () use ($customer, $validated) {
            // A new phone number has not been verified by OTP yet
            if ($customer->phone !== $validated['phone']) {
                $customer->phone_verified_at = null;
                $customer->tokens()->delete();
            }

            $customer->name = trim($validated['name']);
            $customer->phone = $validated['phone'];
            $customer->email = $this->normalizeEmail($validated['email'] ?? null);
            $customer->save();

            $this->saveDefaultAddress($customer, $validated);
        });

        return response()->json([
            'message' => 'Customer updated successfully.',
            'data' => $this->listRow($this->freshListCustomer($customer->id)),
        ]);
    }

    /**
     * Activate / block one or many customers
     */
    public function updateStatus(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'status' => ['required', 'in:active,blocked'],
        ]);

        $customers = Customer::whereIn('id', $validated['ids'])->get();

        DB::transaction(function () use ($customers, $validated) {
            foreach ($customers as $customer) {
                $customer->update(['status' => $validated['status']]);

                // Blocked customers are logged out of the app immediately
                if ($validated['status'] === 'blocked') {
                    $customer->tokens()->delete();
                }
            }
        });

        return response()->json([
            'message' => $customers->count() . ' customer(s) updated successfully.',
            'data' => [
                'ids' => $customers->pluck('id')->values(),
                'status' => $validated['status'],
            ],
        ]);
    }

    // =====================================================
    // HELPERS
    // =====================================================

    private function filteredQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return Customer::query()
            ->withCount('orders')
            ->with(['addresses' => fn ($query) => $query->where('is_default', true)])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $like = '%' . $search . '%';
                    $digits = preg_replace('/\D/', '', $search);

                    $query->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);

                    if ($digits !== '') {
                        // "+91 98765 43210" -> "9876543210"
                        $phoneDigits = strlen($digits) > 10 ? substr($digits, -10) : $digits;
                        $query->orWhere('phone', 'like', '%' . $phoneDigits . '%');
                    }

                    // "CUST-000123" / "123"
                    if (preg_match('/^(?:cust-?)?0*(\d+)$/i', $search, $match)) {
                        $query->orWhere('id', (int) $match[1]);
                    }
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            // Registered within: last 7 days / last 30 days / this calendar year
            ->when($filters['period'] ?? null, fn ($query, $period) => $query->where(
                'customers.created_at',
                '>=',
                match ($period) {
                    '7d' => now()->subDays(7),
                    '30d' => now()->subDays(30),
                    'year' => now()->startOfYear(),
                }
            ));
    }

    private function freshListCustomer(int $id): Customer
    {
        return Customer::withCount('orders')
            ->with(['addresses' => fn ($query) => $query->where('is_default', true)])
            ->findOrFail($id);
    }

    private function baseRow(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'customer_code' => 'CUST-' . str_pad((string) $customer->id, 6, '0', STR_PAD_LEFT),
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'profile_photo' => $customer->profile_photo ? asset('storage/' . $customer->profile_photo) : null,
            'status' => $customer->status,
            'is_verified' => $customer->phone_verified_at !== null,
            'created_at' => $customer->created_at,
            'updated_at' => $customer->updated_at,
        ];
    }

    private function listRow(Customer $customer): array
    {
        $address = $customer->addresses->first();

        return [
            ...$this->baseRow($customer),
            'orders_count' => (int) ($customer->orders_count ?? 0),
            'default_address' => $address ? [
                'address' => $address->address,
                'landmark' => $address->landmark,
                'pincode' => $address->pincode,
            ] : null,
        ];
    }

    private function orderRow(RestaurantOrder $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'restaurant' => $order->restaurant?->restaurant_name ?? '—',
            'items' => $order->items
                ->map(fn ($item) => trim(($item->quantity > 1 ? $item->quantity . ' x ' : '') . ($item->menu_item_name ?: 'Item')))
                ->implode(', '),
            'amount' => (float) $order->total_amount,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'order_status' => $order->order_status,
            'delivery_address' => $order->deliveryAddress?->address,
            'cancel_reason' => $order->order_status === 'cancelled' ? $order->cancel_reason : null,
            'placed_at' => $this->iso($order->placed_at ?? $order->created_at),
            'delivered_at' => $this->iso($order->delivered_at),
            'cancelled_at' => $this->iso($order->cancelled_at),
        ];
    }

    /**
     * Timeline built from real records (account + orders), newest first
     */
    private function activity(Customer $customer, $orders): array
    {
        $events = collect([
            [
                'text' => 'Account created',
                'type' => 'profile',
                'time' => $customer->created_at,
            ],
        ]);

        if ($customer->phone_verified_at) {
            $events->push([
                'text' => 'Mobile number verified',
                'type' => 'verification',
                'time' => $customer->phone_verified_at,
            ]);
        }

        foreach ($orders as $order) {
            $events->push([
                'text' => 'Order placed ' . $order['order_number'],
                'type' => 'order',
                'time' => $order['placed_at'],
            ]);

            if ($order['delivered_at']) {
                $events->push([
                    'text' => 'Order delivered ' . $order['order_number'],
                    'type' => 'order',
                    'time' => $order['delivered_at'],
                ]);
            }

            if ($order['order_status'] === 'cancelled') {
                $events->push([
                    'text' => 'Order cancelled ' . $order['order_number'],
                    'type' => 'order',
                    'time' => $order['cancelled_at'] ?? $order['placed_at'],
                ]);
            }
        }

        return $events
            ->filter(fn ($event) => $event['time'])
            ->sortByDesc(fn ($event) => \Carbon\Carbon::parse($event['time'])->timestamp)
            ->values()
            ->all();
    }

    private function saveDefaultAddress(Customer $customer, array $validated): void
    {
        $address = trim((string) ($validated['address'] ?? ''));

        if ($address === '') {
            return;
        }

        $values = [
            'address' => $address,
            'pincode' => $validated['pincode'] ?? null,
        ];

        $default = $customer->addresses()->where('is_default', true)->first();

        if ($default) {
            $default->update($values);
            return;
        }

        $customer->addresses()->create([
            ...$values,
            'label' => 'Home',
            'receiver_name' => $customer->name,
            'receiver_phone' => $customer->phone,
            'is_default' => true,
        ]);
    }

    /**
     * Uncast order timestamps are app-timezone strings; send them with an offset
     */
    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
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
