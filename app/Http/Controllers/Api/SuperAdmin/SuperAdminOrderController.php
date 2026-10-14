<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\SuperAdmin\Concerns\ResolvesRestaurantLocation;
use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\City;
use App\Models\DeliveryOrder;
use App\Models\OrderStatusHistory;
use App\Models\RestaurantOrder;
use App\Models\Rider;
use App\Services\Order\OrderCancellationService;
use App\Services\Restaurant\RestaurantEarningService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminOrderController extends Controller
{
    use ResolvesRestaurantLocation;

    /**
     * Max orders returned to the Orders page
     */
    private const LIST_LIMIT = 2000;

    /**
     * Active orders older than this are shown as DELAYED
     */
    private const DELAY_MINUTES = 60;

    /**
     * Order lifecycle, in order
     */
    private const FLOW = [
        'pending',
        'accepted',
        'preparing',
        'ready_for_pickup',
        'picked_up',
        'delivered',
    ];

    private const ACTIVE_STATUSES = [
        'pending',
        'accepted',
        'preparing',
        'ready_for_pickup',
        'picked_up',
    ];

    /**
     * Page status -> restaurant_orders.order_status
     */
    private const STATUS_INPUT = [
        'CONFIRMED' => 'accepted',
        'PREPARING' => 'preparing',
        'FOOD READY' => 'ready_for_pickup',
        'PICKED UP' => 'picked_up',
        'OUT FOR DELIVERY' => 'picked_up',
        'DELIVERED' => 'delivered',
        'COMPLETED' => 'delivered',
    ];

    /**
     * Timestamp column set when an order reaches a status
     */
    private const STATUS_TIMESTAMPS = [
        'accepted' => 'accepted_at',
        'preparing' => 'preparing_at',
        'ready_for_pickup' => 'ready_for_pickup_at',
        'picked_up' => 'picked_up_at',
        'delivered' => 'delivered_at',
    ];

    // =====================================================
    // LIST (GET /api/super-admin/orders)
    // =====================================================

    public function index(Request $request)
    {
        $validated = $request->validate([
            'range' => ['nullable', 'in:all,7,30,90'],
        ]);

        // Orders placed in the last N days, or all time
        $since = in_array($validated['range'] ?? 'all', ['7', '30', '90'], true)
            ? now()->subDays((int) $validated['range'])
            : null;

        $orders = RestaurantOrder::query()
            ->when($since, fn ($query) => $query->whereRaw('COALESCE(placed_at, created_at) >= ?', [$since]))
            ->with([
                'restaurant:id,restaurant_name',
                'customer:id,name,phone,email',
                'rider:id,name,first_name,last_name,phone,vehicle_type_id,latitude,longitude,last_location_at',
                'rider.vehicleType:id,name',
                'deliveryAddress:id,label,receiver_name,receiver_phone,address,landmark,pincode',
                'items:id,order_id,menu_item_name,variant_name,quantity,unit_price,total_price',
                'payment:id,order_id,payment_method,payment_status,transaction_id,payment_gateway',
            ])
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $restaurantCities = $this->restaurantCities($orders->pluck('restaurant_id')->unique()->all());

        // Rider acceptance time of the matching delivery orders
        $riderAcceptedAt = DeliveryOrder::query()
            ->whereIn('order_number', $orders->whereNotNull('rider_id')->pluck('order_number'))
            ->pluck('accepted_at', 'order_number');

        return response()->json([
            'message' => 'Orders fetched successfully.',
            'data' => [
                'orders' => $orders
                    ->map(fn ($order) => $this->transform(
                        $order,
                        $restaurantCities[$order->restaurant_id] ?? null,
                        $riderAcceptedAt[$order->order_number] ?? null
                    ))
                    ->values(),
                'stats' => $this->stats($since),
                'cities' => City::query()->orderBy('name')->pluck('name')->values(),
                'riders' => Rider::query()
                    ->where('status', 'approved')
                    ->orderBy('name')
                    ->get(['id', 'name', 'first_name', 'last_name', 'phone', 'is_online'])
                    ->map(fn ($rider) => [
                        'id' => $rider->id,
                        'name' => $this->riderName($rider),
                        'phone' => $rider->phone,
                        'is_online' => (bool) $rider->is_online,
                    ])
                    ->values(),
            ],
        ]);
    }

    // =====================================================
    // UPDATE STATUS (PATCH /api/super-admin/orders/{id}/status)
    // =====================================================

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', array_merge(array_keys(self::STATUS_INPUT), ['RIDER ASSIGNED']))],
        ]);

        $order = RestaurantOrder::find($id);

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if (in_array($order->order_status, ['delivered', 'cancelled'], true)) {
            return response()->json([
                'message' => 'Delivered or cancelled orders cannot change status.',
            ], 422);
        }

        if ($validated['status'] === 'RIDER ASSIGNED') {
            return response()->json([
                'message' => $order->rider_id
                    ? 'A rider is already assigned to this order.'
                    : 'Use Reassign Rider to assign a delivery partner.',
            ], 422);
        }

        $next = self::STATUS_INPUT[$validated['status']];
        $currentRank = array_search($order->order_status, self::FLOW, true);
        $nextRank = array_search($next, self::FLOW, true);

        if ($nextRank <= $currentRank) {
            return response()->json([
                'message' => 'Orders can only move forward in the order flow.',
            ], 422);
        }

        if ($nextRank >= array_search('picked_up', self::FLOW, true) && !$order->rider_id) {
            return response()->json([
                'message' => 'Assign a rider before marking the order as picked up.',
            ], 422);
        }

        DB::transaction(function () use ($order, $next, $nextRank) {
            $now = now();
            $update = ['order_status' => $next];

            // Fill every skipped step so the timeline stays complete
            foreach (array_slice(self::FLOW, 1, $nextRank) as $step) {
                $column = self::STATUS_TIMESTAMPS[$step];

                if (!$order->{$column}) {
                    $update[$column] = $now;
                }
            }

            if ($next === 'delivered' && $order->payment_method === 'cash') {
                $update['payment_status'] = 'paid';
            }

            $order->update($update);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => $next,
                'changed_by' => 'admin',
                'remarks' => 'Order marked as ' . str_replace('_', ' ', $next) . ' by super admin',
            ]);

            $this->syncDeliveryOrder($order, $next, $now);

            // Same as the restaurant / rider flow: earning calculated at
            // handover, credited to the wallet once delivered
            $earningService = app(RestaurantEarningService::class);

            if ($nextRank >= array_search('ready_for_pickup', self::FLOW, true)) {
                $earningService->calculate($order->fresh());
            }

            if ($next === 'delivered') {
                $earningService->credit($order->fresh());
            }
        });

        $this->logOrder($request, $order, 'Updated Order Status', 'Status changed to ' . $validated['status']);

        return response()->json([
            'message' => 'Order status updated successfully.',
        ]);
    }

    // =====================================================
    // ASSIGN RIDER (PATCH /api/super-admin/orders/{id}/rider)
    // =====================================================

    public function assignRider(Request $request, $id)
    {
        $validated = $request->validate([
            'rider_id' => ['required', 'integer', 'exists:riders,id'],
        ]);

        $order = RestaurantOrder::find($id);

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if (in_array($order->order_status, ['delivered', 'cancelled'], true)) {
            return response()->json([
                'message' => 'Riders cannot be assigned to delivered or cancelled orders.',
            ], 422);
        }

        $rider = Rider::find($validated['rider_id']);

        if ($rider->status !== 'approved') {
            return response()->json([
                'message' => 'Only approved delivery partners can be assigned.',
            ], 422);
        }

        DB::transaction(function () use ($order, $rider) {
            $order->update(['rider_id' => $rider->id]);

            $deliveryOrder = DeliveryOrder::where('order_number', $order->order_number)->first();

            if ($deliveryOrder && !in_array($deliveryOrder->status, ['delivered', 'cancelled'], true)) {
                $deliveryOrder->rider_id = $rider->id;

                if ($deliveryOrder->status === 'available') {
                    $deliveryOrder->status = 'accepted';
                    $deliveryOrder->accepted_at = now();
                }

                if ($order->pickup_otp && !$deliveryOrder->pickup_otp) {
                    $deliveryOrder->pickup_otp = $order->pickup_otp;
                }

                $deliveryOrder->save();
            }
        });

        $this->logOrder($request, $order, 'Assigned Rider', 'Rider ' . $this->riderName($rider) . ' (#' . $rider->id . ') assigned');

        return response()->json([
            'message' => 'Rider assigned successfully.',
        ]);
    }

    // =====================================================
    // CANCEL (POST /api/super-admin/orders/cancel)
    // =====================================================

    public function cancel(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reason = trim((string) ($validated['reason'] ?? '')) ?: 'Cancelled by super admin';

        $orders = RestaurantOrder::query()
            ->whereIn('id', $validated['ids'])
            ->get();

        $cancellation = app(OrderCancellationService::class);
        $cancelled = 0;
        $skipped = 0;

        // One transaction per order, so one bad order doesn't undo the rest
        foreach ($orders as $order) {
            try {
                // Not allowed once the rider has picked the food up
                $done = $cancellation->cancel(
                    $order,
                    OrderCancellationService::BY_ADMIN,
                    $reason,
                    OrderCancellationService::ADMIN_CANCELLABLE
                );
            } catch (\Throwable $exception) {
                report($exception);
                $done = false;
            }

            if ($done) {
                $cancelled++;
                $this->logOrder($request, $order, 'Cancelled Order', $reason, 'Medium');
            } else {
                $skipped++;
            }
        }

        $skipped += count(array_unique($validated['ids'])) - $orders->count();

        if ($cancelled === 0) {
            return response()->json([
                'message' => $orders->count() === 1 && in_array($orders->first()->order_status, ['picked_up', 'delivered'], true)
                    ? 'Order has already been picked up by the rider and cannot be cancelled.'
                    : 'No orders were cancelled. Orders that are picked up, delivered or already cancelled cannot be cancelled.',
                'data' => ['updated' => 0, 'skipped' => $skipped],
            ], 422);
        }

        return response()->json([
            'message' => $cancelled . ' order(s) cancelled.'
                . ($skipped ? " {$skipped} skipped (picked up, delivered or already cancelled)." : ''),
            'data' => ['updated' => $cancelled, 'skipped' => $skipped],
        ]);
    }

    // A cancelled order is final: there is no reactivate. Paid ones are refunded.

    // =====================================================
    // HELPERS
    // =====================================================

    private function transform(RestaurantOrder $order, ?string $city, $riderAcceptedAt): array
    {
        $address = $order->deliveryAddress;
        $rider = $order->rider;

        $fullAddress = $address
            ? trim(implode(', ', array_filter([$address->address, $address->landmark])) . ($address->pincode ? ' - ' . $address->pincode : ''), ' ,-')
            : null;

        $items = $order->items->map(fn ($item) => [
            'name' => trim($item->menu_item_name . ($item->variant_name ? ' (' . $item->variant_name . ')' : '')),
            'qty' => (int) $item->quantity,
            'unitPrice' => (float) $item->unit_price,
            'discount' => 0.0,
            'tax' => 0.0,
            'total' => (float) $item->total_price,
        ])->values();

        $status = $this->displayStatus($order);
        $placedAt = $order->placed_at ?? $order->created_at;
        $paymentMethod = $order->payment?->payment_method ?? $order->payment_method;

        return [
            'id' => $order->id,
            'orderId' => '#' . $order->order_number,
            'customer' => $order->customer?->name ?: ($address?->receiver_name ?: ($order->customer?->phone ?? 'Customer')),
            'email' => $order->customer?->email ?: '—',
            'phone' => $order->customer?->phone ?: ($address?->receiver_phone ?? '—'),
            'restaurant' => $order->restaurant?->restaurant_name ?? '—',
            'restaurantId' => '#RES-' . $order->restaurant_id,
            'rider' => $rider ? $this->riderName($rider) : 'Unassigned',
            'riderId' => $rider?->id,
            'riderStatus' => $this->riderStatus($order),
            'amount' => (float) $order->total_amount,
            'payment' => strtoupper((string) ($order->payment?->payment_status ?? $order->payment_status)),
            'status' => $status,
            'rawStatus' => $order->order_status,
            'placedAtIso' => $this->iso($placedAt),
            'city' => $city ?? '—',
            'address' => $address?->address ?? 'Not available',
            'fullAddress' => $fullAddress ?: 'Not available',
            'items' => $items,
            'subtotal' => (float) $order->subtotal,
            'discount' => -1 * abs((float) $order->discount),
            'taxes' => (float) $order->tax + (float) $order->packing_charge,
            'deliveryCharge' => (float) $order->delivery_charge,
            'finalAmount' => (float) $order->total_amount,
            'placedAt' => $this->iso($placedAt),
            'confirmedAt' => $this->iso($order->accepted_at),
            'preparingAt' => $this->iso($order->preparing_at),
            'foodReadyAt' => $this->iso($order->ready_for_pickup_at),
            // No assignment time is stored: use the rider's acceptance, else pickup readiness
            'riderAssignedAt' => $order->rider_id
                ? $this->iso($riderAcceptedAt ?? $order->picked_up_at ?? $order->ready_for_pickup_at)
                : null,
            'pickedUpAt' => $this->iso($order->picked_up_at),
            'outForDeliveryAt' => $this->iso($order->picked_up_at),
            'deliveredAt' => $this->iso($order->delivered_at),
            'cancelledAt' => $this->iso($order->cancelled_at),
            'cancelReason' => $order->cancel_reason,
            'paymentMethod' => $this->paymentMethodLabel($paymentMethod, $order->payment?->payment_gateway),
            'transactionId' => $order->payment?->transaction_id ?: '—',
            'vehicle' => $rider?->vehicleType?->name ?? ($rider ? '—' : 'Not Assigned'),
            'eta' => $this->eta($order, $status),
            'currentLocation' => $this->currentLocation($order, $rider),
            'locationUpdatedIso' => $this->iso($rider?->last_location_at),
        ];
    }

    /**
     * restaurant_orders.order_status -> page status
     */
    private function displayStatus(RestaurantOrder $order): string
    {
        $status = $order->order_status;

        if ($status === 'cancelled') {
            return 'CANCELLED';
        }

        if ($status === 'delivered') {
            return 'DELIVERED';
        }

        if ($order->payment_status === 'failed') {
            return 'FAILED';
        }

        $placedAt = $order->placed_at ?? $order->created_at;

        if ($placedAt && Carbon::parse($placedAt)->lt(now()->subMinutes(self::DELAY_MINUTES))) {
            return 'DELAYED';
        }

        return match ($status) {
            'pending' => 'PENDING',
            'accepted' => 'CONFIRMED',
            'preparing' => 'PREPARING',
            'ready_for_pickup' => $order->rider_id ? 'RIDER ASSIGNED' : 'FOOD READY',
            'picked_up' => 'OUT FOR DELIVERY',
            default => strtoupper(str_replace('_', ' ', $status)),
        };
    }

    /**
     * Counts over every order in the range, not just the rows sent to the page
     */
    private function stats(?Carbon $since = null): array
    {
        $delayedBefore = now()->subMinutes(self::DELAY_MINUTES);
        $active = "'" . implode("','", self::ACTIVE_STATUSES) . "'";

        $row = DB::table('restaurant_orders')
            ->selectRaw('COUNT(*) as `total`')
            ->selectRaw("SUM(CASE WHEN order_status IN ({$active}) AND payment_status != 'failed' AND COALESCE(placed_at, created_at) >= ? THEN 1 ELSE 0 END) as `active`", [$delayedBefore])
            ->selectRaw("SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as `completed`")
            ->selectRaw("SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as `cancelled`")
            ->selectRaw("SUM(CASE WHEN order_status IN ({$active}) AND payment_status = 'failed' THEN 1 ELSE 0 END) as `failed`")
            ->selectRaw("SUM(CASE WHEN order_status IN ({$active}) AND payment_status != 'failed' AND COALESCE(placed_at, created_at) < ? THEN 1 ELSE 0 END) as `delayed`", [$delayedBefore])
            ->selectRaw("SUM(CASE WHEN order_status IN ({$active}) AND rider_id IS NULL THEN 1 ELSE 0 END) as `unassigned`")
            ->when($since, fn ($query) => $query->whereRaw('COALESCE(placed_at, created_at) >= ?', [$since]))
            ->first();

        return collect((array) $row)->map(fn ($value) => (int) $value)->all();
    }

    /**
     * restaurant_id => city name, resolved from the restaurant address
     */
    private function restaurantCities(array $restaurantIds): array
    {
        if (empty($restaurantIds)) {
            return [];
        }

        $cityIds = DB::table('restaurants')
            ->leftJoin('restaurant_addresses as ra', 'ra.restaurant_id', '=', 'restaurants.id')
            ->whereIn('restaurants.id', $restaurantIds)
            ->select('restaurants.id')
            ->selectRaw($this->citySql() . ' as city_id')
            ->pluck('city_id', 'id');

        $names = City::query()->whereIn('id', $cityIds->filter()->unique())->pluck('name', 'id');

        return $cityIds->map(fn ($cityId) => $cityId ? ($names[$cityId] ?? null) : null)->all();
    }

    private function syncDeliveryOrder(RestaurantOrder $order, string $status, Carbon $now): void
    {
        $deliveryOrder = DeliveryOrder::where('order_number', $order->order_number)->first();

        if (!$deliveryOrder || in_array($deliveryOrder->status, ['delivered', 'cancelled'], true)) {
            return;
        }

        if (in_array($status, ['accepted', 'preparing', 'ready_for_pickup'], true)) {
            $deliveryOrder->restaurant_status = $status;
        }

        if ($status === 'ready_for_pickup' && $order->pickup_otp) {
            $deliveryOrder->pickup_otp = $order->pickup_otp;
        }

        if (in_array($status, ['picked_up', 'delivered'], true)) {
            $deliveryOrder->restaurant_status = 'ready_for_pickup';
            $deliveryOrder->rider_id = $deliveryOrder->rider_id ?? $order->rider_id;
            $deliveryOrder->accepted_at = $deliveryOrder->accepted_at ?? $now;
            $deliveryOrder->picked_up_at = $deliveryOrder->picked_up_at ?? $now;
            $deliveryOrder->status = $status;
        }

        if ($status === 'delivered') {
            $deliveryOrder->delivered_at = $deliveryOrder->delivered_at ?? $now;
        }

        $deliveryOrder->save();
    }

    private function riderName(Rider $rider): string
    {
        $name = trim(($rider->first_name ?? '') . ' ' . ($rider->last_name ?? ''));

        return $name !== '' ? $name : ($rider->name ?: ($rider->phone ?? 'Rider #' . $rider->id));
    }

    private function riderStatus(RestaurantOrder $order): string
    {
        if (!$order->rider_id) {
            return 'Unassigned';
        }

        return match ($order->order_status) {
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
            'picked_up' => 'En Route',
            default => 'Assigned',
        };
    }

    private function eta(RestaurantOrder $order, string $status): string
    {
        return match (true) {
            $status === 'DELIVERED' => 'Delivered',
            $status === 'CANCELLED' => 'Cancelled',
            $status === 'FAILED' => 'Failed',
            $status === 'DELAYED' => 'Delayed',
            !$order->rider_id => 'Pending',
            default => 'In progress',
        };
    }

    private function currentLocation(RestaurantOrder $order, ?Rider $rider): string
    {
        if ($order->order_status === 'delivered') {
            return 'Order delivered';
        }

        if ($order->order_status === 'cancelled') {
            return 'Order cancelled';
        }

        if (!$rider) {
            return 'Awaiting rider assignment';
        }

        if ($rider->latitude !== null && $rider->longitude !== null) {
            return number_format((float) $rider->latitude, 5) . ', ' . number_format((float) $rider->longitude, 5);
        }

        return 'Location not available';
    }

    private function paymentMethodLabel(?string $method, ?string $gateway): string
    {
        $label = match (strtolower((string) $method)) {
            'cash', 'cod' => 'Cash on Delivery',
            'online' => 'Online',
            'wallet' => 'Wallet',
            default => $method ? ucfirst(strtolower($method)) : '—',
        };

        return $gateway ? $label . ' • ' . $gateway : $label;
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }

    private function logOrder(Request $request, RestaurantOrder $order, string $action, string $summary, string $risk = 'Low'): void
    {
        // Logging must never break the actual change
        try {
            $admin = $request->user();

            AdminActivityLog::create([
                'admin_id' => $admin?->id,
                'admin_name' => $admin?->name,
                'admin_email' => $admin?->email,
                'action' => $action,
                'module' => 'Orders',
                'target' => '#' . $order->order_number,
                'change_summary' => $summary,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'source' => 'Super Admin Panel',
                'method' => $request->method(),
                'path' => $request->path(),
                'risk_level' => $risk,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
