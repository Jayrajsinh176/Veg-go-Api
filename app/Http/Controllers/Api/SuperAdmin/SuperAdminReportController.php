<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Restaurant;
use App\Models\RestaurantOffer;
use App\Models\RestaurantOrder;
use App\Models\Rider;
use App\Models\RiderWeeklySettlement;
use App\Models\RiderEarning;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SuperAdminReportController extends Controller
{
    /**
     * Order date used everywhere on the report
     */
    private const ORDER_DATE_SQL = 'COALESCE(restaurant_orders.placed_at, restaurant_orders.created_at)';

    /**
     * "vs previous period" compares today's all-time figures with the
     * all-time figures as they were this many days ago.
     */
    private const COMPARE_DAYS = 30;

    /**
     * A delivery is "delayed" when it reaches the customer later than this
     * many minutes after the order was placed.
     */
    private const SLA_MINUTES = 45;

    /**
     * Reports & Analytics (super admin).
     *
     * All-time platform data. KPI changes compare the current totals with the
     * totals as of 30 days ago. The weekly charts cover the 4 most recent weeks
     * that have activity (ending on the latest order / sign-up date).
     */
    public function index()
    {
        $now = now();
        $cutoff = $now->copy()->subDays(self::COMPARE_DAYS)->startOfDay();

        $orders = $this->loadOrders();
        $previous = $orders->filter(fn ($order) => $order->report_at->lt($cutoff))->values();

        // Charts end on the latest order date (today when there are no orders)
        $chartEnd = ($orders->max('report_at') ?? $now)->copy()->startOfDay();
        $chartStart = $chartEnd->copy()->subDays(29);

        $partners = $this->deliveryPartners($orders, $previous);

        return response()->json([
            'message' => 'Reports fetched successfully.',
            'data' => [
                'period' => [
                    'start' => $chartStart->toDateString(),
                    'end' => $chartEnd->toDateString(),
                    'compare_days' => self::COMPARE_DAYS,
                    'sla_minutes' => self::SLA_MINUTES,
                ],
                'weeks' => $this->weeks($chartStart),
                'growthWeeks' => $this->growthWeeks($now),
                'changes' => $this->changes($orders, $previous, $cutoff),
                'users' => $this->users(),
                'restaurants' => $this->restaurants(),
                'deliveryPartners' => $partners,
                'delivery' => $this->deliverySummary($orders, $previous),
                'zones' => $this->zones($orders, $previous, $partners),
                'orders' => $this->orders($orders, $chartStart),
                'disputes' => $this->refunds($orders),
                'settings' => [
                    'settlementTime' => $this->settlementTime(),
                ],
                'deliveryTrend' => $this->deliveryTrend($orders, $chartEnd),
                'userStats' => $this->userStats($orders, $cutoff),
                'userMonths' => $this->userMonths($now),
                'cohorts' => $this->cohorts($orders, $now),
                'deliveryPeriods' => $this->deliveryPeriods($orders, $chartEnd),
                'transactions' => $this->transactions($orders),
                'finEndDate' => $chartEnd->toDateString(),
                'marketing' => $this->marketing($orders, $chartEnd),
                'generated_at' => $now,
            ],
        ]);
    }

    // =====================================================
    // DATA LOADING
    // =====================================================

    /**
     * All orders, with the commission resolved the same way as the dashboard
     * (stored when credited, current rate otherwise).
     */
    private function loadOrders(): Collection
    {
        $defaultRate = (float) config('restaurant.commission_percentage', 30.00);
        $rateSql = "COALESCE(restaurants.commission_percentage, {$defaultRate})";

        return RestaurantOrder::query()
            ->join('restaurants', 'restaurants.id', '=', 'restaurant_orders.restaurant_id')
            ->select([
                'restaurant_orders.id',
                'restaurant_orders.order_number',
                'restaurant_orders.restaurant_id',
                'restaurant_orders.customer_id',
                'restaurant_orders.rider_id',
                'restaurant_orders.subtotal',
                'restaurant_orders.delivery_charge',
                'restaurant_orders.packing_charge',
                'restaurant_orders.tax',
                'restaurant_orders.discount',
                'restaurant_orders.total_amount',
                'restaurant_orders.payment_method',
                'restaurant_orders.commission_percentage',
                'restaurant_orders.restaurant_earning',
                'restaurant_orders.earning_credited_at',
                'restaurant_orders.order_status',
                'restaurant_orders.payment_status',
                'restaurant_orders.earning_status',
                'restaurant_orders.cancel_reason',
                'restaurant_orders.placed_at',
                'restaurant_orders.picked_up_at',
                'restaurant_orders.delivered_at',
                'restaurant_orders.created_at',
                'restaurant_orders.updated_at',
                'restaurants.restaurant_name',
                'restaurants.owner_name',
                'restaurants.commission_percentage as restaurant_commission_rate',
            ])
            ->selectRaw("CASE WHEN restaurant_orders.earning_status = 'pending' THEN ROUND(restaurant_orders.subtotal * {$rateSql} / 100, 2) ELSE restaurant_orders.commission_amount END as resolved_commission")
            ->get()
            ->each(function ($order) {
                $order->report_at = Carbon::parse($order->placed_at ?? $order->created_at);
                $order->counts_as_revenue = $order->order_status !== 'cancelled'
                    && $order->earning_status !== 'reversed';
            });
    }

    // =====================================================
    // PERIODS
    // =====================================================

    /**
     * Four buckets cover the full 30-day report range.
     */
    private function weeks(Carbon $start): array
    {
        $offset = 0;

        return collect([7, 7, 8, 8])
            ->map(function ($length, $index) use ($start, &$offset) {
                $bucketStart = $offset;
                $bucketEnd = $offset + $length - 1;
                $offset += $length;

                return [
                    'label' => 'Week ' . ($index + 1),
                    'start' => $bucketStart + 1,
                    'end' => $bucketEnd + 1,
                    'startDate' => $start->copy()->addDays($bucketStart)->toDateString(),
                    'endDate' => $start->copy()->addDays($bucketEnd)->toDateString(),
                ];
            })
            ->all();
    }

    /**
     * Five consecutive 7-day buckets ending on the latest customer /
     * restaurant sign-up (used by the growth lines).
     */
    private function growthWeeks(Carbon $now): array
    {
        $latest = collect([
            Customer::max('created_at'),
            Restaurant::where('status', '!=', 'draft')->max('created_at'),
        ])->filter()->map(fn ($date) => Carbon::parse($date))->max() ?? $now;

        $labels = ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Current'];

        return collect($labels)
            ->map(function ($label, $index) use ($latest) {
                $end = $latest->copy()->subDays((4 - $index) * 7)->startOfDay();
                $start = $end->copy()->subDays(6);

                return [
                    'label' => $label,
                    'startDate' => $start->toDateString(),
                    'endDate' => $end->toDateString(),
                ];
            })
            ->all();
    }

    // =====================================================
    // KPI CHANGES (now vs 30 days ago)
    // =====================================================

    private function changes(Collection $current, Collection $previous, Carbon $start): array
    {
        $validNow = $current->where('counts_as_revenue', true);
        $validPrev = $previous->where('counts_as_revenue', true);

        $restaurants = Restaurant::where('status', 'approved');
        $riders = Rider::where('status', 'approved');

        return [
            'users' => $this->growth(Customer::count(), Customer::where('created_at', '<', $start)->count()),
            'restaurants' => $this->growth((clone $restaurants)->count(), (clone $restaurants)->where('created_at', '<', $start)->count()),
            'partners' => $this->growth((clone $riders)->count(), (clone $riders)->where('created_at', '<', $start)->count()),
            'orders' => $this->growth($current->count(), $previous->count()),
            'revenue' => $this->growth((float) $validNow->sum('total_amount'), (float) $validPrev->sum('total_amount')),
            'commission' => $this->growth((float) $validNow->sum('resolved_commission'), (float) $validPrev->sum('resolved_commission')),
        ];
    }

    // =====================================================
    // RECORDS
    // =====================================================

    private function users(): array
    {
        $stats = RestaurantOrder::query()
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(*) as orders')
            ->selectRaw("SUM(CASE WHEN order_status != 'cancelled' THEN total_amount ELSE 0 END) as spend")
            ->selectRaw('MAX(COALESCE(placed_at, created_at)) as last_order_at')
            ->get()
            ->keyBy('customer_id');

        return Customer::query()
            ->latest('id')
            ->get(['id', 'name', 'phone', 'status', 'created_at'])
            ->map(function ($customer) use ($stats) {
                $row = $stats->get($customer->id);

                return [
                    'id' => 'USR-' . $customer->id,
                    'name' => $customer->name ?: ($customer->phone ? '+91 ' . $customer->phone : 'Customer #' . $customer->id),
                    // Active = has ordered at least once, Inactive = never ordered
                    'status' => match (true) {
                        $customer->status === 'blocked' => 'Blocked',
                        (int) ($row->orders ?? 0) > 0 => 'Active',
                        default => 'Inactive',
                    },
                    'joined' => optional($customer->created_at)->toDateString(),
                    'orders' => (int) ($row->orders ?? 0),
                    'spend' => round((float) ($row->spend ?? 0), 2),
                    'lastOrder' => $row?->last_order_at ? Carbon::parse($row->last_order_at)->toDateString() : null,
                ];
            })
            ->values()
            ->all();
    }

    private function restaurants(): array
    {
        $stats = RestaurantOrder::query()
            ->groupBy('restaurant_id')
            ->selectRaw('restaurant_id, COUNT(*) as orders')
            ->selectRaw("SUM(CASE WHEN order_status != 'cancelled' THEN total_amount ELSE 0 END) as revenue")
            ->get()
            ->keyBy('restaurant_id');

        return Restaurant::query()
            ->where('status', '!=', 'draft')
            ->with('document.outletType:id,name')
            ->latest('id')
            ->get(['id', 'restaurant_name', 'owner_name', 'status', 'created_at'])
            ->map(function ($restaurant) use ($stats) {
                $row = $stats->get($restaurant->id);

                return [
                    'id' => 'RES-' . $restaurant->id,
                    'name' => $restaurant->restaurant_name ?: ($restaurant->owner_name ?: 'Restaurant #' . $restaurant->id),
                    'category' => $restaurant->document?->outletType?->name ?? '—',
                    'status' => match ($restaurant->status) {
                        'approved' => 'Active',
                        'pending_approval' => 'Pending',
                        default => ucfirst((string) $restaurant->status),
                    },
                    'joined' => optional($restaurant->created_at)->toDateString(),
                    'orders' => (int) ($row->orders ?? 0),
                    'revenue' => round((float) ($row->revenue ?? 0), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One row per rider with their all-time delivery performance.
     */
    private function deliveryPartners(Collection $current, Collection $previous): array
    {
        $currentByRider = $current->whereNotNull('rider_id')->groupBy('rider_id');
        $previousByRider = $previous->whereNotNull('rider_id')->groupBy('rider_id');

        $earnings = RiderEarning::query()
            ->groupBy('rider_id')
            ->selectRaw('rider_id, SUM(amount) as total')
            ->pluck('total', 'rider_id');

        return Rider::query()
            ->with(['workZone:id,name', 'cityArea:id,name', 'city:id,name'])
            ->latest('id')
            ->get()
            ->map(function ($rider) use ($currentByRider, $previousByRider, $earnings) {
                $orders = $currentByRider->get($rider->id, collect());
                $delivered = $orders->where('order_status', 'delivered');
                $previousDelivered = $previousByRider->get($rider->id, collect())->where('order_status', 'delivered');

                $avg = $this->avgDeliveryMinutes($delivered);
                $previousAvg = $this->avgDeliveryMinutes($previousDelivered);

                return [
                    'id' => 'RID-' . $rider->id,
                    'name' => $rider->name ?: ($rider->phone ? '+91 ' . $rider->phone : 'Rider #' . $rider->id),
                    'status' => match ($rider->status) {
                        'approved' => 'Active',
                        'blocked' => 'Suspended',
                        default => 'Inactive',
                    },
                    'joined' => optional($rider->created_at)->toDateString(),
                    'deliveries' => $orders->where('order_status', '!=', 'cancelled')->count(),
                    'completed' => $delivered->count(),
                    'delayed' => $delivered->filter(fn ($order) => $this->isDelayed($order))->count(),
                    'avgMinutes' => $avg !== null ? (int) round($avg) : 0,
                    'previousAvgMinutes' => $previousAvg !== null ? (int) round($previousAvg) : null,
                    'rating' => null,
                    'earnings' => round((float) ($earnings[$rider->id] ?? 0), 2),
                    'zone' => $this->zoneName($rider),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * All orders. "day" is the day number counted from the chart start (day 1).
     */
    private function orders(Collection $current, Carbon $start): array
    {
        return $current
            ->sortBy('report_at')
            ->map(fn ($order) => [
                'id' => $order->order_number ?: 'ORD-' . $order->id,
                'date' => $order->report_at->toDateString(),
                'restaurant' => $order->restaurant_name ?: ($order->owner_name ?: 'Restaurant #' . $order->restaurant_id),
                'amount' => round((float) $order->total_amount, 2),
                'commission' => $order->counts_as_revenue ? round((float) $order->resolved_commission, 2) : 0,
                'status' => ucwords(str_replace('_', ' ', (string) $order->order_status)),
                'day' => intdiv($order->report_at->copy()->startOfDay()->getTimestamp() - $start->copy()->startOfDay()->getTimestamp(), 86400) + 1,
            ])
            ->values()
            ->all();
    }

    /**
     * Refunded orders.
     */
    private function refunds(Collection $current): array
    {
        return $current
            ->where('payment_status', 'refunded')
            ->map(fn ($order) => [
                'id' => 'RFD-' . $order->id,
                'type' => 'Refund',
                'status' => 'Refunded',
                'amount' => round((float) $order->total_amount, 2),
                'orderId' => $order->order_number ?: 'ORD-' . $order->id,
                'date' => $order->report_at->toDateString(),
                'reason' => $order->cancel_reason ?: 'Payment refunded',
            ])
            ->values()
            ->all();
    }

    // =====================================================
    // DELIVERY
    // =====================================================

    private function deliverySummary(Collection $current, Collection $previous): array
    {
        $delivered = $current->where('order_status', 'delivered');
        $previousDelivered = $previous->where('order_status', 'delivered');

        $avg = $this->avgDeliveryMinutes($delivered);
        $previousAvg = $this->avgDeliveryMinutes($previousDelivered);

        return [
            'avgMinutes' => $avg !== null ? (int) round($avg) : 0,
            'previousAvgMinutes' => $previousAvg !== null ? (int) round($previousAvg) : null,
            'totalDeliveries' => $current->where('order_status', '!=', 'cancelled')->count(),
            'completedDeliveries' => $delivered->count(),
            'delayedDeliveries' => $delivered->filter(fn ($order) => $this->isDelayed($order))->count(),
        ];
    }

    /**
     * Avg delivery time per rider zone (only zones that have riders).
     */
    private function zones(Collection $current, Collection $previous, array $partners): array
    {
        $zoneOfRider = collect($partners)->mapWithKeys(fn ($p) => [(int) substr($p['id'], 4) => $p['zone']]);

        return collect($partners)
            ->groupBy('zone')
            ->map(function ($records, $zone) use ($current, $previous, $zoneOfRider) {
                $zone = (string) $zone;
                $inZone = fn ($order) => $order->rider_id && $zoneOfRider->get($order->rider_id) === $zone;

                $orders = $current->filter($inZone);
                $delivered = $orders->where('order_status', 'delivered');
                $avg = $this->avgDeliveryMinutes($delivered);
                $previousAvg = $this->avgDeliveryMinutes($previous->filter($inZone)->where('order_status', 'delivered'));

                return [
                    'zone' => $zone,
                    'minutes' => $avg !== null ? (int) round($avg) : 0,
                    'previousMinutes' => $previousAvg !== null ? (int) round($previousAvg) : null,
                    'totalDeliveries' => $orders->where('order_status', '!=', 'cancelled')->count(),
                    'completedDeliveries' => $delivered->count(),
                    'delayedDeliveries' => $delivered->filter(fn ($order) => $this->isDelayed($order))->count(),
                ];
            })
            ->sortByDesc('totalDeliveries')
            ->values()
            ->all();
    }

    /**
     * The 7 days ending on the latest order date, one entry per day.
     */
    private function deliveryTrend(Collection $current, Carbon $now): array
    {
        return collect(range(6, 0))
            ->map(function ($daysAgo) use ($current, $now) {
                $day = $now->copy()->subDays($daysAgo)->toDateString();
                $orders = $current->filter(fn ($order) => $order->rider_id && $order->report_at->toDateString() === $day);
                $delivered = $orders->where('order_status', 'delivered');
                $avg = $this->avgDeliveryMinutes($delivered);

                return [
                    'label' => Carbon::parse($day)->format('D'),
                    'deliveries' => $orders->where('order_status', '!=', 'cancelled')->count(),
                    'completed' => $delivered->count(),
                    'delayed' => $delivered->filter(fn ($order) => $this->isDelayed($order))->count(),
                    'avgMinutes' => $avg !== null ? (int) round($avg) : 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Average gap between a rider's settlement week end and payout date, as "T+N".
     */
    private function settlementTime(): string
    {
        $days = RiderWeeklySettlement::query()
            ->where('status', 'successful')
            ->get(['week_end', 'payout_date'])
            ->map(fn ($row) => Carbon::parse($row->week_end)->diffInDays(Carbon::parse($row->payout_date)))
            ->avg();

        return $days === null ? '—' : 'T+' . (int) round($days);
    }

    // =====================================================
    // USERS TAB
    // =====================================================

    /**
     * Users tab KPIs. Changes compare with the same figure as of 30 days ago
     * (for new registrations: with the 30 days before that).
     */
    private function userStats(Collection $orders, Carbon $cutoff): array
    {
        $customers = Customer::query()->get(['id', 'status', 'created_at']);
        $orderedEver = $orders->pluck('customer_id')->filter()->unique();
        $orderedBefore = $orders->filter(fn ($o) => $o->report_at->lt($cutoff))->pluck('customer_id')->filter()->unique();

        $notBlocked = $customers->where('status', '!=', 'blocked');
        $active = $notBlocked->whereIn('id', $orderedEver)->count();

        $existedBefore = $notBlocked->filter(fn ($c) => $c->created_at && $c->created_at->lt($cutoff));
        $activeBefore = $existedBefore->whereIn('id', $orderedBefore)->count();

        $newNow = $customers->filter(fn ($c) => $c->created_at && $c->created_at->gte($cutoff))->count();
        $newPrev = $customers->filter(fn ($c) => $c->created_at
            && $c->created_at->gte($cutoff->copy()->subDays(self::COMPARE_DAYS))
            && $c->created_at->lt($cutoff))->count();

        return [
            'total' => $customers->count(),
            'periodStart' => $cutoff->toDateString(),
            'newUsers' => $newNow,
            'active' => $active,
            'inactive' => $notBlocked->count() - $active,
            'blocked' => $customers->where('status', 'blocked')->count(),
            'changes' => [
                'total' => $this->growth($customers->count(), $customers->filter(fn ($c) => $c->created_at && $c->created_at->lt($cutoff))->count()),
                'newUsers' => $this->growth($newNow, $newPrev),
                'active' => $this->growth($active, $activeBefore),
                'inactive' => $this->growth($notBlocked->count() - $active, $existedBefore->count() - $activeBefore),
                'blocked' => null,
            ],
        ];
    }

    /**
     * Last 5 calendar months (registration trend)
     */
    private function userMonths(Carbon $now): array
    {
        return collect(range(4, 0))
            ->map(function ($monthsAgo) use ($now) {
                $month = $now->copy()->startOfMonth()->subMonthsNoOverflow($monthsAgo);

                return [
                    'label' => $month->format('M'),
                    'startDate' => $month->toDateString(),
                    'endDate' => $month->copy()->endOfMonth()->toDateString(),
                ];
            })
            ->all();
    }

    /**
     * Retention cohorts for the last 3 sign-up months: % of each cohort that
     * ordered in the sign-up month (M0) and in each of the 3 months after.
     */
    private function cohorts(Collection $orders, Carbon $now): array
    {
        $customers = Customer::query()->get(['id', 'created_at']);
        $orderMonths = $orders
            ->filter(fn ($o) => $o->customer_id)
            ->groupBy('customer_id')
            ->map(fn ($items) => $items->map(fn ($o) => $o->report_at->format('Y-m'))->unique()->values());

        return collect(range(2, 0))
            ->map(function ($monthsAgo) use ($customers, $orderMonths, $now) {
                $month = $now->copy()->startOfMonth()->subMonthsNoOverflow($monthsAgo);
                $cohort = $customers->filter(fn ($c) => $c->created_at && $c->created_at->format('Y-m') === $month->format('Y-m'));

                $cells = collect(range(0, 3))->map(function ($offset) use ($cohort, $orderMonths, $month, $now) {
                    $target = $month->copy()->addMonthsNoOverflow($offset);

                    if ($target->gt($now) || $cohort->isEmpty()) {
                        return ['rate' => null, 'userIds' => []];
                    }

                    $retained = $cohort->filter(fn ($c) => $orderMonths->get($c->id, collect())->contains($target->format('Y-m')));

                    return [
                        'rate' => round($retained->count() / $cohort->count() * 100, 1),
                        'userIds' => $retained->map(fn ($c) => 'USR-' . $c->id)->values()->all(),
                    ];
                })->all();

                return [
                    'label' => $month->format('M'),
                    'size' => $cohort->count(),
                    'cells' => $cells,
                ];
            })
            ->all();
    }

    // =====================================================
    // DELIVERY TAB
    // =====================================================

    /**
     * 7D (daily), 30D (weekly) and 90D (monthly) delivery figures, each
     * ending on the latest order date.
     */
    private function deliveryPeriods(Collection $orders, Carbon $end): array
    {
        $riders = Rider::query()->get(['id', 'status', 'created_at']);
        $approved = $riders->where('status', 'approved');

        return collect(['7D' => 7, '30D' => 30, '90D' => 90])
            ->map(function ($days, $key) use ($orders, $end, $riders, $approved) {
                $start = $end->copy()->subDays($days - 1)->startOfDay();
                $periodEnd = $end->copy()->endOfDay();
                $prevStart = $start->copy()->subDays($days);

                $current = $orders->filter(fn ($o) => $o->report_at->gte($start) && $o->report_at->lte($periodEnd));
                $previous = $orders->filter(fn ($o) => $o->report_at->gte($prevStart) && $o->report_at->lt($start));

                $delivered = $current->where('order_status', 'delivered');
                $previousDelivered = $previous->where('order_status', 'delivered');
                $avg = $this->avgDeliveryMinutes($delivered);
                $previousAvg = $this->avgDeliveryMinutes($previousDelivered);

                $createdBefore = fn ($r) => $r->created_at && $r->created_at->lt($start);
                $newPartners = $riders->filter(fn ($r) => $r->created_at && $r->created_at->between($start, $periodEnd))->count();
                $newPartnersPrev = $riders->filter(fn ($r) => $r->created_at && $r->created_at->gte($prevStart) && $r->created_at->lt($start))->count();

                return [
                    'label' => 'Last ' . $days . ' Days',
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                    'trend' => $this->deliveryBuckets($current, $start, $key),
                    'completed' => $delivered->count(),
                    'total' => $current->where('order_status', '!=', 'cancelled')->count(),
                    'avgMinutes' => $avg !== null ? (int) round($avg) : 0,
                    'previousAvg' => $previousAvg !== null ? (int) round($previousAvg) : 0,
                    'earnings' => round((float) RiderEarning::whereBetween('created_at', [$start, $periodEnd])->sum('amount'), 2),
                    'payouts' => round((float) RiderWeeklySettlement::where('status', 'successful')
                        ->whereBetween('payout_date', [$start->toDateString(), $end->toDateString()])
                        ->sum('total_amount'), 2),
                    'newPartners' => $newPartners,
                    'changes' => [
                        'total' => $this->growth($riders->count(), $riders->filter($createdBefore)->count()),
                        'active' => $this->growth($approved->count(), $approved->filter($createdBefore)->count()),
                        'newPartners' => $this->growth($newPartners, $newPartnersPrev),
                        'inactive' => null,
                        'suspended' => null,
                        'completed' => $this->growth($delivered->count(), $previousDelivered->count()),
                    ],
                ];
            })
            ->all();
    }

    private function deliveryBuckets(Collection $orders, Carbon $start, string $period): array
    {
        $ranges = match ($period) {
            '7D' => collect(range(0, 6))->map(fn ($i) => [$i, 1]),
            '30D' => collect([[0, 7], [7, 7], [14, 8], [22, 8]]),
            default => collect([[0, 30], [30, 30], [60, 30]]),
        };

        return $ranges
            ->map(function ($range, $index) use ($orders, $start, $period) {
                [$offset, $length] = $range;
                $from = $start->copy()->addDays($offset);
                $to = $from->copy()->addDays($length);

                $bucket = $orders->filter(fn ($o) => $o->report_at->gte($from) && $o->report_at->lt($to));
                $delivered = $bucket->where('order_status', 'delivered');
                $avg = $this->avgDeliveryMinutes($delivered);

                return [
                    'label' => match ($period) {
                        '7D' => $from->format('D'),
                        '30D' => 'Week ' . ($index + 1),
                        default => $to->copy()->subDay()->format('M'),
                    },
                    'deliveries' => $bucket->where('order_status', '!=', 'cancelled')->count(),
                    'completed' => $delivered->count(),
                    'delayed' => $delivered->filter(fn ($o) => $this->isDelayed($o))->count(),
                    'avgMinutes' => $avg !== null ? (int) round($avg) : 0,
                ];
            })
            ->values()
            ->all();
    }

    // =====================================================
    // FINANCIAL TAB
    // =====================================================

    /**
     * One ledger row per order.
     *
     * Status: Refunded (payment refunded), Failed (payment failed, or
     * cancelled without a refund), Settled (restaurant earning credited),
     * Processing (everything else).
     */
    private function transactions(Collection $orders): array
    {
        $riderEarnings = DB::table('delivery_orders')
            ->whereIn('order_number', $orders->pluck('order_number')->filter()->values())
            ->pluck('rider_earning', 'order_number');

        $customers = Customer::query()->get(['id', 'name', 'phone'])->keyBy('id');
        $riders = Rider::query()->with(['workZone:id,name', 'cityArea:id,name', 'city:id,name'])->get()->keyBy('id');
        $categories = Restaurant::query()->with('document.outletType:id,name')->get(['id'])
            ->mapWithKeys(fn ($r) => [$r->id => $r->document?->outletType?->name ?? '—']);
        $defaultRate = (float) config('restaurant.commission_percentage', 30.00);

        return $orders
            ->sortBy('report_at')
            ->map(function ($order) use ($riderEarnings, $customers, $riders, $categories, $defaultRate) {
                $status = match (true) {
                    $order->payment_status === 'refunded' => 'Refunded',
                    $order->payment_status === 'failed', $order->order_status === 'cancelled' => 'Failed',
                    $order->earning_status === 'credited' => 'Settled',
                    default => 'Processing',
                };

                $itemTotal = (float) $order->subtotal;
                $discount = (float) $order->discount;
                $deliveryCharge = (float) $order->delivery_charge;
                $gross = (float) $order->total_amount;
                $commission = $order->counts_as_revenue ? (float) $order->resolved_commission : 0;
                $rate = $order->earning_status === 'pending'
                    ? (float) ($order->restaurant_commission_rate ?? $defaultRate)
                    : (float) $order->commission_percentage;

                $paidOut = in_array($status, ['Settled', 'Processing'], true);
                $restaurantPayout = $status === 'Settled' ? (float) $order->restaurant_earning : $itemTotal - $commission;
                $riderPayout = (float) ($riderEarnings[$order->order_number] ?? 0);

                $customer = $customers->get($order->customer_id);
                $rider = $riders->get($order->rider_id);

                return [
                    'id' => 'TRX-' . $order->id,
                    'orderId' => $order->order_number ?: 'ORD-' . $order->id,
                    'date' => $order->report_at->toDateString(),
                    'time' => $order->report_at->format('H:i'),
                    'sortKey' => $order->report_at->getTimestamp(),
                    'entity' => $order->restaurant_name ?: ($order->owner_name ?: 'Restaurant #' . $order->restaurant_id),
                    'entityId' => 'RES-' . $order->restaurant_id,
                    'entityCategory' => $categories->get($order->restaurant_id, '—'),
                    'customer' => $customer ? ($customer->name ?: '+91 ' . $customer->phone) : '—',
                    'customerId' => $customer ? 'USR-' . $customer->id : '—',
                    'rider' => $rider ? ($rider->name ?: '+91 ' . $rider->phone) : '—',
                    'riderId' => $rider ? 'RID-' . $rider->id : '—',
                    'zone' => $rider ? $this->zoneName($rider) : '—',
                    'payment' => match ($order->payment_method) {
                        'cash' => 'Cash',
                        'wallet' => 'Wallet',
                        default => 'Online',
                    },
                    'status' => $status,
                    'itemTotal' => round($itemTotal, 2),
                    'discount' => round($discount, 2),
                    'deliveryCharge' => round($deliveryCharge, 2),
                    'tax' => round((float) $order->tax, 2),
                    'gross' => round($gross, 2),
                    'commission' => round($commission, 2),
                    'commissionRate' => round($rate / 100, 4),
                    'restaurantPayout' => $paidOut ? round($restaurantPayout, 2) : 0,
                    'riderPayout' => $paidOut ? round($riderPayout, 2) : 0,
                    'payout' => $paidOut ? round($restaurantPayout + $riderPayout, 2) : 0,
                    'refund' => $status === 'Refunded' ? round($gross, 2) : 0,
                    'netRevenue' => in_array($status, ['Failed', 'Refunded'], true)
                        ? 0
                        : round($commission + $deliveryCharge - $discount - $riderPayout, 2),
                    'linkedOrder' => true,
                    'settlementId' => $status === 'Settled' ? 'STL-' . $order->id : '—',
                    'settlementDate' => $status === 'Settled' && $order->earning_credited_at
                        ? Carbon::parse($order->earning_credited_at)->toDateString()
                        : '—',
                ];
            })
            ->values()
            ->all();
    }

    // =====================================================
    // MARKETING TAB
    // =====================================================

    /**
     * Campaigns = restaurant offers + coupons.
     *
     * Marketing views are not tracked. eligibleOrders counts matching orders
     * during each campaign period, and usage counts orders that used it.
     */
    private function marketing(Collection $orders, Carbon $end): array
    {
        $today = now()->startOfDay();
        $valid = $orders->where('order_status', '!=', 'cancelled');

        $offerUses = DB::table('restaurant_order_items')
            ->whereNotNull('offer_id')
            ->select('offer_id', 'order_id')
            ->distinct()
            ->get();
        $ordersWithOffer = $offerUses->pluck('order_id')->unique();

        $campaignStatus = fn ($active, $start, $finish) => match (true) {
            $start && $start->gt($today) => 'SCHEDULED',
            ! $active, $finish && $finish->lt($today) => 'COMPLETED',
            default => 'ACTIVE',
        };

        $offers = RestaurantOffer::query()
            ->with('menuItem:id,name')
            ->get()
            ->map(function ($offer) use ($valid, $offerUses, $campaignStatus) {
                $start = $offer->start_date->copy()->startOfDay();
                $finish = $offer->end_date->copy()->endOfDay();

                $eligibleOrders = $valid->filter(fn ($o) => (int) $o->restaurant_id === (int) $offer->restaurant_id
                    && $o->report_at->between($start, $finish));
                $eligible = $eligibleOrders->count();
                $usage = $offerUses
                    ->where('offer_id', $offer->id)
                    ->whereIn('order_id', $eligibleOrders->pluck('id'))
                    ->count();

                return [
                    'id' => 'OFR-' . $offer->id,
                    'name' => ($offer->menuItem?->name ?? 'Menu item') . ' · ' . $offer->label(),
                    'type' => 'Discount',
                    'start' => $offer->start_date->toDateString(),
                    'end' => $offer->end_date->toDateString(),
                    'eligibleOrders' => $eligible,
                    'usage' => $usage,
                    'performance' => $eligible ? (int) round($usage / $eligible * 100) : 0,
                    'status' => $campaignStatus($offer->is_active, $start, $finish),
                ];
            });

        $coupons = Coupon::query()
            ->get()
            ->map(function ($coupon) use ($valid, $ordersWithOffer, $campaignStatus) {
                $start = Carbon::parse($coupon->starts_at ?? $coupon->created_at);
                $finish = $coupon->expires_at ? Carbon::parse($coupon->expires_at) : null;

                $eligible = $valid->filter(fn ($o) => $o->report_at->gte($start)
                    && (! $finish || $o->report_at->lte($finish))
                    && (float) $o->subtotal >= (float) $coupon->min_order_amount);

                // Orders don't store the coupon code: a discounted order with
                // no item offer is counted as a coupon use.
                $usage = $eligible->filter(fn ($o) => (float) $o->discount > 0 && ! $ordersWithOffer->contains($o->id))->count();

                return [
                    'id' => 'CPN-' . $coupon->id,
                    'name' => $coupon->code,
                    'type' => 'Coupon',
                    'start' => $start->toDateString(),
                    'end' => $finish ? $finish->toDateString() : '—',
                    'eligibleOrders' => $eligible->count(),
                    'usage' => $usage,
                    'performance' => $eligible->count() ? (int) round($usage / $eligible->count() * 100) : 0,
                    'status' => $campaignStatus($coupon->is_active, $start, $finish),
                ];
            });

        $campaigns = $offers->concat($coupons)->sortByDesc('start')->values();

        $usedDiscount = fn ($o) => (float) $o->discount > 0 || $ordersWithOffer->contains($o->id);

        $trend = collect(['7D' => 7, '30D' => 30, '90D' => 90])
            ->map(function ($days, $key) use ($valid, $end, $usedDiscount) {
                $start = $end->copy()->subDays($days - 1)->startOfDay();
                $ranges = match ($key) {
                    '7D' => collect(range(0, 6))->map(fn ($i) => [$i, 1]),
                    '30D' => collect([[0, 6], [6, 6], [12, 6], [18, 6], [24, 6]]),
                    default => collect([[0, 30], [30, 30], [60, 30]]),
                };

                return $ranges->map(function ($range) use ($valid, $start, $key, $usedDiscount) {
                    [$offset, $length] = $range;
                    $from = $start->copy()->addDays($offset);
                    $to = $from->copy()->addDays($length);
                    $bucket = $valid->filter(fn ($o) => $o->report_at->gte($from) && $o->report_at->lt($to));

                    return [
                        'label' => $key === '90D' ? $to->copy()->subDay()->format('M') : $from->format('M j'),
                        'orders' => $bucket->count(),
                        'usage' => $bucket->filter($usedDiscount)->count(),
                    ];
                })->values()->all();
            })
            ->all();

        $typeData = collect(['Discount', 'Featured', 'Coupon', 'VegGo'])
            ->map(fn ($type) => [
                'name' => $type,
                'value' => (int) round($campaigns->where('type', $type)->avg('performance') ?? 0),
            ])
            ->all();

        $ratings = DB::table('restaurant_reviews')
            ->groupBy('restaurant_id')
            ->selectRaw('restaurant_id, AVG(rating) as rating')
            ->pluck('rating', 'restaurant_id');

        $featured = $valid->groupBy('restaurant_id')
            ->map(fn ($items, $restaurantId) => [
                'name' => $items->first()->restaurant_name ?: ($items->first()->owner_name ?: 'Restaurant #' . $restaurantId),
                'rating' => isset($ratings[$restaurantId]) ? round((float) $ratings[$restaurantId], 1) : null,
                'reach' => $items->pluck('customer_id')->filter()->unique()->count(),
                'orders' => $items->count(),
            ])
            ->sortByDesc('orders')
            ->take(4)
            ->values();

        $recent = $valid->filter(fn ($o) => $o->report_at->gt($end->copy()->subDays(30)))->count();
        $before = $valid->filter(fn ($o) => $o->report_at->lte($end->copy()->subDays(30)) && $o->report_at->gt($end->copy()->subDays(60)))->count();
        $featuredRating = $featured->pluck('rating')->filter()->avg();

        return [
            'campaigns' => $campaigns->all(),
            'trend' => $trend,
            'typeData' => $typeData,
            'featured' => $featured->all(),
            'couponUsage' => $coupons->sum('usage'),
            'featuredRating' => $featuredRating !== null ? round($featuredRating, 1) : null,
            'reachChange' => $this->growth($recent, $before),
        ];
    }

    // =====================================================
    // HELPERS
    // =====================================================

    private function zoneName(Rider $rider): string
    {
        return $rider->workZone?->name
            ?? $rider->cityArea?->name
            ?? $rider->city?->name
            ?? 'Unassigned';
    }

    /**
     * Pickup to doorstep, in minutes.
     */
    private function avgDeliveryMinutes(Collection $delivered): ?float
    {
        $minutes = $delivered
            ->map(fn ($order) => $this->minutesBetween($order->picked_up_at, $order->delivered_at))
            ->filter(fn ($value) => $value !== null);

        return $minutes->isEmpty() ? null : $minutes->avg();
    }

    private function isDelayed($order): bool
    {
        $minutes = $this->minutesBetween($order->placed_at ?? $order->created_at, $order->delivered_at);

        return $minutes !== null && $minutes > self::SLA_MINUTES;
    }

    private function minutesBetween($from, $to): ?float
    {
        if (! $from || ! $to) {
            return null;
        }

        $seconds = Carbon::parse($to)->getTimestamp() - Carbon::parse($from)->getTimestamp();

        return $seconds >= 0 ? $seconds / 60 : null;
    }

    /**
     * % change from $previous to $current (null when there is nothing to compare with).
     */
    private function growth(float $current, float $previous): ?float
    {
        return $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : null;
    }
}
