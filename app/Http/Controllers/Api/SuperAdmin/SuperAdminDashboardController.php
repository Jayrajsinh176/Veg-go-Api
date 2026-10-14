<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Restaurant;
use App\Models\RestaurantOrder;
use App\Models\Rider;
use App\Models\RiderWeeklySettlement;
use App\Models\RiderSupportTicket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SuperAdminDashboardController extends Controller
{
    /**
     * Order date used everywhere on the dashboard
     */
    private const ORDER_DATE_SQL = 'COALESCE(restaurant_orders.placed_at, restaurant_orders.created_at)';

    /**
     * Statuses of an order that is still in progress
     */
    private const ACTIVE_ORDER_STATUSES = [
        'pending',
        'accepted',
        'preparing',
        'ready_for_pickup',
        'picked_up',
    ];

    /**
     * Super Admin dashboard (System Overview + Dashboard Insights)
     */
    public function index()
    {
        $now = now();

        return response()->json([
            'message' => 'Super admin dashboard fetched successfully.',
            'data' => [
                'overview' => [
                    'users' => $this->users($now),
                    'restaurants' => $this->restaurants($now),
                    'fleet' => $this->fleet($now),
                    'orders_24h' => $this->orders24h($now),
                    'finance' => $this->finance(),
                    'support' => $this->support(),
                ],
                'insights' => [
                    'order_growth' => $this->orderGrowth($now),
                    'order_status' => $this->orderStatus(),
                    'delivery_performance' => $this->deliveryPerformance(),
                ],
                'recent_orders' => $this->recentOrders(),
                'recent_applications' => $this->recentApplications(),
                'generated_at' => $now,
            ],
        ]);
    }

    // =====================================================
    // SYSTEM OVERVIEW
    // =====================================================

    private function users(Carbon $now): array
    {
        $total = Customer::count();

        return [
            'total' => $total,
            'active' => Customer::where('status', 'active')->count(),
            'new_24h' => Customer::where('created_at', '>=', $now->copy()->subDay())->count(),
            'growth_30d' => $this->growth($total, Customer::where('created_at', '>=', $now->copy()->subDays(30))->count()),
        ];
    }

    private function restaurants(Carbon $now): array
    {
        // Draft = signup not finished yet, so not counted as a partner
        $partners = Restaurant::where('status', '!=', 'draft');

        $total = (clone $partners)->count();

        return [
            'total' => $total,
            'active' => Restaurant::where('status', 'approved')->count(),
            'pending' => Restaurant::where('status', 'pending_approval')->count(),
            'growth_30d' => $this->growth($total, (clone $partners)->where('created_at', '>=', $now->copy()->subDays(30))->count()),
        ];
    }

    private function fleet(Carbon $now): array
    {
        $total = Rider::count();

        return [
            'total' => $total,
            'active' => Rider::where('status', 'approved')->count(),
            'online' => Rider::where('status', 'approved')->where('is_online', true)->count(),
            'pending' => Rider::where('status', 'pending')->count(),
            'growth_30d' => $this->growth($total, Rider::where('created_at', '>=', $now->copy()->subDays(30))->count()),
        ];
    }

    private function orders24h(Carbon $now): array
    {
        $row = RestaurantOrder::query()
            ->where(DB::raw(self::ORDER_DATE_SQL), '>=', $now->copy()->subDay())
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->first();

        $total = (int) $row->total;
        $completed = (int) $row->completed;
        $cancelled = (int) $row->cancelled;

        return [
            'total' => $total,
            'active' => $total - $completed - $cancelled,
            'completed' => $completed,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * Same rules as AdminRestaurantEarningController: commission is taken on
     * the food subtotal. Credited orders use the stored commission, pending
     * orders use the restaurant's current rate (or the default rate).
     */
    private function finance(): array
    {
        $defaultRate = (float) config('restaurant.commission_percentage', 30.00);
        $rateSql = "COALESCE(restaurants.commission_percentage, {$defaultRate})";
        $pendingCommissionSql = "ROUND(restaurant_orders.subtotal * {$rateSql} / 100, 2)";

        $row = RestaurantOrder::query()
            ->join('restaurants', 'restaurants.id', '=', 'restaurant_orders.restaurant_id')
            ->where('restaurant_orders.order_status', '!=', 'cancelled')
            ->where('restaurant_orders.earning_status', '!=', 'reversed')
            ->selectRaw('SUM(restaurant_orders.total_amount) as gross_value')
            ->selectRaw("SUM(CASE WHEN restaurant_orders.earning_status = 'pending' THEN {$pendingCommissionSql} ELSE restaurant_orders.commission_amount END) as commission")
            ->selectRaw("SUM(CASE WHEN restaurant_orders.earning_status = 'pending' THEN restaurant_orders.subtotal - {$pendingCommissionSql} ELSE 0 END) as restaurant_pending")
            ->first();

       $riderPending = (float) RiderWeeklySettlement::whereIn(
    'status',
    ['pending', 'processing']
)->sum('total_amount');
        return [
            'gross_value' => round((float) $row->gross_value, 2),
            'commission' => round((float) $row->commission, 2),
            'pending_settlements' => round((float) $row->restaurant_pending + $riderPending, 2),
        ];
    }

    private function support(): array
    {
        $counts = RiderSupportTicket::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending = (int) ($counts['pending'] ?? 0);
        $inProgress = (int) ($counts['in_progress'] ?? 0);
        $resolved = (int) ($counts['resolved'] ?? 0);

        return [
            'open' => $pending + $inProgress,
            'pending' => $pending,
            'in_progress' => $inProgress,
            'resolved' => $resolved,
            'total' => $pending + $inProgress + $resolved,
        ];
    }

    // =====================================================
    // DASHBOARD INSIGHTS
    // =====================================================

    /**
     * Orders per day for the last 7 days, and growth vs the 7 days before
     */
    private function orderGrowth(Carbon $now): array
    {
        $start = $now->copy()->subDays(6)->startOfDay();
        $previousStart = $start->copy()->subDays(7);

        $perDay = RestaurantOrder::query()
            ->where(DB::raw(self::ORDER_DATE_SQL), '>=', $start)
            ->selectRaw('DATE(' . self::ORDER_DATE_SQL . ') as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $days = collect(range(0, 6))->map(function ($offset) use ($start, $perDay) {
            $date = $start->copy()->addDays($offset);

            return [
                'date' => $date->toDateString(),
                'label' => $date->format('D'),
                'orders' => (int) ($perDay[$date->toDateString()] ?? 0),
            ];
        });

        $thisWeek = $days->sum('orders');

        $lastWeek = RestaurantOrder::query()
            ->where(DB::raw(self::ORDER_DATE_SQL), '>=', $previousStart)
            ->where(DB::raw(self::ORDER_DATE_SQL), '<', $start)
            ->count();

        return [
            'days' => $days->values(),
            'this_week' => $thisWeek,
            'last_week' => $lastWeek,
            'growth' => $lastWeek > 0
                ? round((($thisWeek - $lastWeek) / $lastWeek) * 100, 1)
                : null,
        ];
    }

    /**
     * All-time order status split. "Failed" = payment failed on a non-cancelled order.
     */
    private function orderStatus(): array
    {
        $row = RestaurantOrder::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN order_status IN ('" . implode("','", self::ACTIVE_ORDER_STATUSES) . "') AND payment_status != 'failed' THEN 1 ELSE 0 END) as active")
            ->selectRaw("SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->selectRaw("SUM(CASE WHEN order_status IN ('" . implode("','", self::ACTIVE_ORDER_STATUSES) . "') AND payment_status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->first();

        return [
            'total' => (int) $row->total,
            'active' => (int) $row->active,
            'completed' => (int) $row->completed,
            'cancelled' => (int) $row->cancelled,
            'failed' => (int) $row->failed,
        ];
    }

    private function deliveryPerformance(): array
    {
        $delivered = RestaurantOrder::where('order_status', 'delivered')->count();
        $cancelled = RestaurantOrder::where('order_status', 'cancelled')->count();
        $finished = $delivered + $cancelled;

        return [
            'online' => Rider::where('status', 'approved')->where('is_online', true)->count(),
            'on_delivery' => DeliveryOrder::whereIn('status', ['accepted', 'picked_up'])
                ->whereNotNull('rider_id')
                ->distinct()
                ->count('rider_id'),
            'pending_applications' => Rider::where('status', 'pending')->count(),
            'success_rate' => $finished > 0
                ? round(($delivered / $finished) * 100, 1)
                : null,
        ];
    }

    private function recentOrders(): array
    {
        return RestaurantOrder::query()
            ->with([
                'restaurant:id,restaurant_name',
                'items:id,order_id,menu_item_name,quantity',
            ])
            ->latest('id')
            ->limit(4)
            ->get(['id', 'order_number', 'restaurant_id', 'total_amount', 'order_status', 'payment_status', 'placed_at', 'created_at'])
            ->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'restaurant' => $order->restaurant?->restaurant_name ?? '—',
                'items' => $order->items
                    ->map(fn ($item) => [
                        'name' => $item->menu_item_name ?: 'Item',
                        'quantity' => (int) $item->quantity,
                    ])
                    ->values()
                    ->all(),
                'total_quantity' => (int) $order->items->sum('quantity'),
                'amount' => (float) $order->total_amount,
                'status' => $this->orderStatusLabel($order),
                'placed_at' => $order->placed_at ?? $order->created_at,
            ])
            ->all();
    }

    /**
     * Latest restaurant + rider applications (draft restaurants excluded)
     */
    private function recentApplications(): array
    {
        $restaurants = Restaurant::query()
            ->where('status', '!=', 'draft')
            ->latest('updated_at')
            ->limit(4)
            ->get(['id', 'restaurant_name', 'owner_name', 'status', 'updated_at'])
            ->map(fn ($restaurant) => [
                'id' => 'restaurant-' . $restaurant->id,
                'type' => 'restaurant',
                'name' => $restaurant->restaurant_name ?: ($restaurant->owner_name ?: 'Unnamed Restaurant'),
                'status' => match ($restaurant->status) {
                    'pending_approval' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'suspended' => 'Suspended',
                    default => ucfirst((string) $restaurant->status),
                },
                'date' => $restaurant->updated_at,
            ]);

        $riders = Rider::query()
            ->with('kyc:id,rider_id,status')
            ->latest('updated_at')
            ->limit(4)
            ->get(['id', 'name', 'phone', 'status', 'updated_at'])
            ->map(fn ($rider) => [
                'id' => 'rider-' . $rider->id,
                'type' => 'rider',
                'name' => $rider->name ?: $rider->phone,
                'status' => match (true) {
                    $rider->status === 'approved' => 'Approved',
                    $rider->status === 'rejected' => 'Rejected',
                    in_array($rider->kyc?->status, ['under_review', 'info_required'], true) => 'Review',
                    default => 'Pending',
                },
                'date' => $rider->updated_at,
            ]);

        return $restaurants
            ->concat($riders)
            ->sortByDesc('date')
            ->take(4)
            ->values()
            ->all();
    }

    // =====================================================
    // HELPERS
    // =====================================================

    /**
     * % growth over the last 30 days: new / (total before those 30 days)
     */
    private function growth(int $total, int $new): ?float
    {
        $before = $total - $new;

        return $before > 0 ? round(($new / $before) * 100, 1) : null;
    }

    private function orderStatusLabel(RestaurantOrder $order): string
    {
        return match (true) {
            $order->order_status === 'delivered' => 'Completed',
            $order->order_status === 'cancelled' => 'Cancelled',
            $order->payment_status === 'failed' => 'Failed',
            default => 'Active',
        };
    }
}
