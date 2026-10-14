<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\SuperAdmin\Concerns\ResolvesRestaurantLocation;
use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\Restaurant;
use App\Models\RestaurantOrder;
use App\Models\Rider;
use App\Models\RiderWeeklySettlement;
use App\Models\RiderEarning;
use App\Services\Restaurant\RestaurantEarningService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SuperAdminFinanceController extends Controller
{
    use ResolvesRestaurantLocation;

    /**
     * Restaurant earnings are credited once the order is handed to the rider
     * (RestaurantOrderService), so only these orders can be cleared manually
     */
    // Restaurant earning is credited once the order is delivered
    private const CREDITABLE_STATUSES = ['delivered'];

    // =====================================================
    // OVERVIEW (GET /api/super-admin/finance)
    // =====================================================

    /**
     * Figures follow RestaurantOrderService / RestaurantEarningService:
     * commission is taken on the food subtotal. Credited orders use the
     * stored commission, pending orders what it will be at the current rate.
     *
     * range: 7d | 30d | 90d | year
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'range' => ['nullable', 'in:7d,30d,90d,year'],
        ]);

        $range = $validated['range'] ?? '7d';

        [$start, $end] = $this->period($range);

        // Previous period of the same length, for the "vs last period" change
        if ($range === 'year') {
            $prevStart = $start->copy()->subYear();
            $prevEnd = $end->copy()->subYear();
        } else {
            $prevStart = $start->copy()->subSeconds((int) $start->diffInSeconds($end));
            $prevEnd = $start->copy();
        }

        $orders = $this->orders($start, $end);
        $prevOrders = $this->orders($prevStart, $prevEnd);

        $riderEarnings = RiderEarning::query()
            ->whereBetween('created_at', [$start, $end])
            ->get(['rider_id', 'type', 'amount', 'created_at']);

        $prevRiderTotal = (float) RiderEarning::query()
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->sum('amount');

        $current = $this->summarize($orders, (float) $riderEarnings->sum('amount'));
        $previous = $this->summarize($prevOrders, $prevRiderTotal);

        $unpaidRiderPayouts = (float) RiderWeeklySettlement::query()
            ->whereDate('week_end', '>=', $start)
            ->whereIn('status', ['pending', 'processing', 'failed'])
            ->where('total_amount', '>', 0)
            ->sum('total_amount');

        $valid = $orders->where('order_status', '!=', 'cancelled');
        $subtotal = (float) $valid->sum('subtotal');

        $buckets = $this->buckets($range, $start, $end);

        return response()->json([
            'message' => 'Finance overview fetched successfully.',
            'data' => [
                'metrics' => [
                    'grossOrderValue' => $current['grossOrderValue'],
                    'netRevenue' => $current['netRevenue'],
                    'commission' => $current['commission'],
                    'restaurantEarnings' => $current['restaurantEarnings'],
                    'riderEarnings' => $current['riderEarnings'],
                    'pendingPayouts' => round($unpaidRiderPayouts, 2),
                    'pendingSettlements' => $current['pendingSettlements'],
                    'refunds' => $current['refunds'],
                ],
                'changes' => collect(['grossOrderValue', 'netRevenue', 'commission', 'restaurantEarnings', 'riderEarnings', 'refunds'])
                    ->mapWithKeys(fn ($key) => [$key => $this->change($current[$key], $previous[$key])]),
                'totals' => [
                    'deliveryCharges' => round((float) $valid->sum('delivery_charge'), 2),
                    'discounts' => round((float) $valid->sum('discount'), 2),
                    'taxes' => round((float) $valid->sum('tax'), 2),
                    'payments' => round((float) $valid->where('payment_status', 'paid')->sum('total_amount'), 2),
                ],
                // Effective rate on this period's orders, else the default rate
                'commissionRate' => $subtotal > 0
                    ? round(($current['commission'] / $subtotal) * 100, 1)
                    : (float) config('restaurant.commission_percentage', 30.00),
                'charts' => [
                    'revenue' => $this->series($buckets, $valid, fn ($o) => (float) $o->total_amount),
                    'commission' => $this->series($buckets, $valid, fn ($o) => $o->commission_value),
                    'restaurant' => $this->series($buckets, $valid, fn ($o) => $o->earning_value),
                    'rider' => $this->series($buckets, $riderEarnings, fn ($e) => (float) $e->amount, 'created_at'),
                ],
                'restaurants' => $this->restaurantRows($valid),
                'riders' => $this->riderRows($riderEarnings, $start),
            ],
        ]);
    }

    // =====================================================
    // COMMISSION (PUT /api/super-admin/finance/restaurants/{id}/commission)
    // =====================================================

    /**
     * New rate applies to orders not yet credited; credited orders keep theirs
     */
    public function updateCommission(Request $request, $id)
    {
        $validated = $request->validate([
            'commission' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $restaurant = Restaurant::find($id);

        if (!$restaurant) {
            return response()->json(['message' => 'Restaurant not found.'], 404);
        }

        $old = $restaurant->commissionRate();

        $restaurant->update(['commission_percentage' => $validated['commission']]);

        $this->log(
            $request,
            $restaurant,
            'Commission Updated',
            'Commission: ' . $old . '% → ' . (float) $validated['commission'] . '%'
        );

        return response()->json([
            'message' => 'Commission updated successfully.',
        ]);
    }

    // =====================================================
    // CLEAR PAYOUT (POST /api/super-admin/finance/restaurants/{id}/clear-payout)
    // =====================================================

    /**
     * Credits the restaurant wallet for delivered orders still awaiting credit
     */
    public function clearRestaurantPayout(Request $request, $id, RestaurantEarningService $earningService)
    {
        $restaurant = Restaurant::find($id);

        if (!$restaurant) {
            return response()->json(['message' => 'Restaurant not found.'], 404);
        }

        $orders = RestaurantOrder::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('earning_status', 'pending')
            ->whereIn('order_status', self::CREDITABLE_STATUSES)
            ->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'message' => 'No delivered orders are awaiting credit for this restaurant.',
            ], 422);
        }

        $credited = 0.0;

        foreach ($orders as $order) {
            $credited += (float) $earningService->creditOrderEarning($order)->restaurant_earning;
        }

        $this->log(
            $request,
            $restaurant,
            'Payout Cleared',
            'Credited ₹' . number_format($credited, 2) . ' for ' . $orders->count() . ' order(s)',
            'Medium'
        );

        return response()->json([
            'message' => 'Restaurant payout cleared successfully.',
        ]);
    }

    // =====================================================
    // HELPERS
    // =====================================================

    private function period(string $range): array
    {
        $end = now();

        $start = match ($range) {
            '30d' => now()->subDays(29)->startOfDay(),
            '90d' => now()->subDays(89)->startOfDay(),
            'year' => now()->startOfYear(),
            default => now()->subDays(6)->startOfDay(),
        };

        return [$start, $end];
    }

    /**
     * Orders placed in the period, with commission / restaurant earning
     * resolved (stored once credited, else estimated at the current rate)
     */
    private function orders(Carbon $start, Carbon $end): Collection
    {
        $defaultRate = (float) config('restaurant.commission_percentage', 30.00);

        return RestaurantOrder::query()
            ->leftJoin('restaurants', 'restaurants.id', '=', 'restaurant_orders.restaurant_id')
            ->whereBetween(DB::raw('COALESCE(restaurant_orders.placed_at, restaurant_orders.created_at)'), [$start, $end])
            ->get([
                'restaurant_orders.id',
                'restaurant_orders.restaurant_id',
                'restaurant_orders.order_status',
                'restaurant_orders.payment_status',
                'restaurant_orders.earning_status',
                'restaurant_orders.subtotal',
                'restaurant_orders.delivery_charge',
                'restaurant_orders.tax',
                'restaurant_orders.discount',
                'restaurant_orders.total_amount',
                'restaurant_orders.commission_amount',
                'restaurant_orders.restaurant_earning',
                'restaurant_orders.placed_at',
                'restaurant_orders.created_at',
                'restaurants.commission_percentage as restaurant_rate',
            ])
            ->each(function ($order) use ($defaultRate) {
                $isCredited = $order->earning_status === 'credited';
                $rate = $order->restaurant_rate !== null ? (float) $order->restaurant_rate : $defaultRate;

                $commission = $isCredited
                    ? (float) $order->commission_amount
                    : round((float) $order->subtotal * $rate / 100, 2);

                $order->commission_value = $order->earning_status === 'reversed' ? 0.0 : $commission;
                $order->earning_value = match ($order->earning_status) {
                    'credited' => (float) $order->restaurant_earning,
                    'reversed' => 0.0,
                    default => round((float) $order->subtotal - $commission, 2),
                };
                $order->report_at = Carbon::parse($order->placed_at ?? $order->created_at);
            });
    }

    private function summarize(Collection $orders, float $riderTotal): array
    {
        $valid = $orders->where('order_status', '!=', 'cancelled');

        $gross = round((float) $valid->sum('total_amount'), 2);
        $restaurant = round((float) $valid->sum('earning_value'), 2);
        $rider = round($riderTotal, 2);

        return [
            'grossOrderValue' => $gross,
            // What VegGo keeps once restaurants and riders are paid
            'netRevenue' => round($gross - $restaurant - $rider, 2),
            'commission' => round((float) $valid->sum('commission_value'), 2),
            'restaurantEarnings' => $restaurant,
            'riderEarnings' => $rider,
            'pendingSettlements' => round((float) $valid
                ->where('earning_status', 'pending')
                ->whereIn('order_status', self::CREDITABLE_STATUSES)
                ->sum('earning_value'), 2),
            'refunds' => round((float) $orders->where('payment_status', 'refunded')->sum('total_amount'), 2),
        ];
    }

    private function change(float $current, float $previous): string
    {
        if ($previous == 0.0) {
            return $current > 0 ? '+100%' : '+0.0%';
        }

        $percent = (($current - $previous) / abs($previous)) * 100;

        return ($percent >= 0 ? '+' : '') . number_format($percent, 1) . '%';
    }

    /**
     * Chart buckets: one per day for 7 days, otherwise 5 equal slices
     */
    private function buckets(string $range, Carbon $start, Carbon $end): array
    {
        $buckets = [];

        if ($range === '7d') {
            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                $buckets[] = [
                    'label' => $day->format('M j'),
                    'from' => $day->copy()->startOfDay(),
                    'to' => $day->copy()->endOfDay(),
                ];
            }

            return $buckets;
        }

        $days = $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
        $size = (int) ceil($days / 5);

        for ($i = 0; $i < 5; $i++) {
            $from = $start->copy()->addDays($i * $size)->startOfDay();

            if ($from->gt($end)) {
                break;
            }

            $to = $i === 4 ? $end->copy() : $from->copy()->addDays($size - 1)->endOfDay();

            $buckets[] = [
                'label' => $i === 4 ? 'Current' : $from->format('M j'),
                'from' => $from,
                'to' => $to,
            ];
        }

        return $buckets;
    }

    private function series(array $buckets, Collection $items, callable $value, string $dateKey = 'report_at'): array
    {
        return array_map(function ($bucket) use ($items, $value, $dateKey) {
            $total = $items
                ->filter(function ($item) use ($bucket, $dateKey) {
                    $at = Carbon::parse($item->{$dateKey});

                    return $at->gte($bucket['from']) && $at->lte($bucket['to']);
                })
                ->sum($value);

            return ['label' => $bucket['label'], 'value' => round((float) $total, 2)];
        }, $buckets);
    }

    private function restaurantRows(Collection $valid): array
    {
        $byRestaurant = $valid->groupBy('restaurant_id');

        $restaurants = Restaurant::query()
            ->where(fn ($query) => $query->where('status', 'approved')->orWhereIn('id', $byRestaurant->keys()))
            ->with('document.outletType:id,name')
            ->orderBy('restaurant_name')
            ->get();

        // Latest commission change per restaurant from the activity log
        $commissionLogs = AdminActivityLog::query()
            ->where('action', 'Commission Updated')
            ->whereIn('target', $restaurants->map(fn ($r) => '#RES-' . $r->id))
            ->latest('id')
            ->get(['target', 'admin_name', 'created_at'])
            ->unique('target')
            ->keyBy('target');

        return $restaurants->map(function ($restaurant) use ($byRestaurant, $commissionLogs) {
            $orders = $byRestaurant->get($restaurant->id, collect());
            $credited = $orders->where('earning_status', 'credited');
            $pending = $orders->where('earning_status', 'pending');

            $grossSales = round((float) $orders->sum('subtotal'), 2);
            $commission = round((float) $orders->sum('commission_value'), 2);
            $netEarnings = round((float) $orders->sum('earning_value'), 2);
            $pendingAmount = round((float) $pending->sum('earning_value'), 2);

            $log = $commissionLogs->get('#RES-' . $restaurant->id);

            return [
                'id' => 'REST-' . $restaurant->id,
                'name' => $restaurant->restaurant_name ?: ($restaurant->owner_name ?: 'Restaurant #' . $restaurant->id),
                'cuisine' => $restaurant->document?->outletType?->name ?: 'Restaurant',
                'orders' => $orders->count(),
                'grossSales' => $grossSales,
                'commissionRate' => $restaurant->commissionRate(),
                'veggoCommission' => $commission,
                'netEarnings' => $netEarnings,
                'paid' => round((float) $credited->sum('earning_value'), 2),
                'pending' => $pendingAmount,
                'payoutStatus' => $pendingAmount > 0 ? 'Pending' : 'Cleared',
                'lastUpdated' => $log
                    ? Carbon::parse($log->created_at)->format('M d, Y') . ' • by ' . ($log->admin_name ?: 'Admin')
                    : Carbon::parse($restaurant->updated_at ?? $restaurant->created_at)->format('M d, Y') . ' • by System',
            ];
        })->values()->all();
    }

    private function riderRows(Collection $earnings, Carbon $start): array
    {
        $byRider = $earnings->groupBy('rider_id');

        $deliveries = DB::table('delivery_orders')
            ->where('status', 'delivered')
            ->whereNotNull('rider_id')
            ->where('delivered_at', '>=', $start)
            ->groupBy('rider_id')
            ->select('rider_id', DB::raw('COUNT(*) as deliveries'))
            ->pluck('deliveries', 'rider_id');

        $paid = RiderWeeklySettlement::query()
            ->whereDate('week_end', '>=', $start)
            ->where('status', 'successful')
            ->groupBy('rider_id')
            ->select('rider_id', DB::raw('SUM(total_amount) as paid_amount'))
            ->pluck('paid_amount', 'rider_id');

        $activeIds = $byRider->keys()->merge($deliveries->keys())->unique();

        return Rider::query()
            ->where(fn ($query) => $query->where('status', 'approved')->orWhereIn('id', $activeIds))
            ->orderBy('name')
            ->get(['id', 'name', 'phone'])
            ->map(function ($rider) use ($byRider, $deliveries, $paid) {
                $rows = $byRider->get($rider->id, collect());

                $sum = fn (string $type) => round((float) $rows->where('type', $type)->sum('amount'), 2);

                $total = round((float) $rows->sum('amount'), 2);
                $pending = round($total - (float) ($paid->get($rider->id) ?? 0), 2);

                return [
                    'id' => 'RDR-' . $rider->id,
                    'name' => $rider->name ?: 'Rider #' . $rider->id,
                    'deliveries' => (int) ($deliveries->get($rider->id) ?? 0),
                    'deliveryEarnings' => $sum('delivery'),
                    'incentives' => $sum('incentive'),
                    'bonuses' => $sum('bonus'),
                    'adjustments' => $sum('adjustment'),
                    'totalEarnings' => $total,
                    'payoutStatus' => $pending > 0 ? 'Pending' : 'Cleared',
                    'phone' => $rider->phone ?: '—',
                ];
            })
            ->values()
            ->all();
    }
}
