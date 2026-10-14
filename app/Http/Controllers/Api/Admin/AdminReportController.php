<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Restaurant;
use App\Models\RestaurantOrder;
use App\Models\RestaurantReview;
use App\Models\Rider;
use App\Models\RiderEarning;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AdminReportController extends Controller
{
    /**
     * An order counts as "on time" when delivered within this many
     * minutes of being placed.
     */
    private const SLA_MINUTES = 45;

    /**
     * Number of bars in each chart (the selected range is split evenly).
     */
    private const BUCKETS = 5;

    /**
     * Reports & analytics for the admin panel.
     *
     * Every metric is computed for the selected range and compared
     * with the previous range of the same length.
     */
    public function index(Request $request)
    {
        $request->validate([
            'range' => 'nullable|in:7d,30d,90d,year',
        ]);

        [$start, $end, $label] = $this->resolveRange($request->input('range', '30d'));

        $length = abs($end->getTimestamp() - $start->getTimestamp());
        $prevStart = $start->copy()->subSeconds($length);

        $orders = RestaurantOrder::query()
            ->select([
                'id',
                'restaurant_id',
                'customer_id',
                'rider_id',
                'total_amount',
                'commission_amount',
                'restaurant_earning',
                'earning_status',
                'order_status',
                'placed_at',
                'accepted_at',
                'ready_for_pickup_at',
                'picked_up_at',
                'delivered_at',
                'created_at',
            ])
            ->whereRaw('COALESCE(placed_at, created_at) BETWEEN ? AND ?', [$prevStart, $end])
            ->get()
            ->each(function ($order) {
                $order->report_at = Carbon::parse($order->placed_at ?? $order->created_at);
            });

        $current = $orders->filter(fn ($order) => $order->report_at->gte($start))->values();
        $previous = $orders->filter(fn ($order) => $order->report_at->lt($start))->values();

        $buckets = $this->buckets($start, $end);

        $context = compact('start', 'end', 'prevStart', 'current', 'previous', 'buckets');

        return response()->json([
            'message' => 'Reports fetched successfully.',

            'data' => [
                'range' => [
                    'label' => $label,
                    'start' => $start->toDateTimeString(),
                    'end' => $end->toDateTimeString(),
                    'sla_minutes' => self::SLA_MINUTES,
                ],

                'tabs' => [
                    'Users' => $this->usersReport($context),
                    'Restaurants' => $this->restaurantsReport($context),
                    'Delivery' => $this->deliveryReport($context),
                    'Orders' => $this->ordersReport($context),
                    'Revenue & Financial' => $this->revenueReport($context),
                ],
            ],
        ]);
    }

    // =====================================================
    // USERS
    // =====================================================

    private function usersReport(array $ctx): array
    {
        ['start' => $start, 'end' => $end, 'prevStart' => $prevStart, 'current' => $current, 'previous' => $previous, 'buckets' => $buckets] = $ctx;

        $totalUsers = Customer::where('created_at', '<=', $end)->count();
        $totalUsersBefore = Customer::where('created_at', '<', $start)->count();

        $newUsers = Customer::whereBetween('created_at', [$start, $end])->get(['id', 'created_at']);
        $newUsersPrev = Customer::whereBetween('created_at', [$prevStart, $start])->count();

        $activeUsers = $current->pluck('customer_id')->filter()->unique()->count();
        $activeUsersPrev = $previous->pluck('customer_id')->filter()->unique()->count();

        $ordersPerUser = $activeUsers ? $current->whereNotNull('customer_id')->count() / $activeUsers : 0;
        $ordersPerUserPrev = $activeUsersPrev ? $previous->whereNotNull('customer_id')->count() / $activeUsersPrev : 0;

        // Table: one row per customer, ranked by spend in the range
        $byCustomer = $current->whereNotNull('customer_id')->groupBy('customer_id');

        $rows = Customer::query()
            ->get(['id', 'name', 'phone', 'status'])
            ->map(function ($customer) use ($byCustomer) {
                $orders = $byCustomer->get($customer->id, collect());
                $valid = $orders->where('order_status', '!=', 'cancelled');
                $spend = (float) $valid->sum('total_amount');

                return [
                    'sort' => $spend,
                    'cells' => [
                        $customer->name ?: ($customer->phone ? '+91 ' . $customer->phone : 'Customer #' . $customer->id),
                        $this->cell($orders->count(), 'count'),
                        $this->cell($spend, 'currency'),
                        $this->cell($valid->count() ? $spend / $valid->count() : 0, 'currency'),
                        $this->cell(optional($orders->max('report_at'))->toDateTimeString(), 'date'),
                        $customer->status === 'blocked'
                            ? 'Blocked'
                            : $this->tier($orders->count(), [5, 2, 1]),
                    ],
                ];
            })
            ->sortByDesc('sort')
            ->pluck('cells')
            ->values();

        return [
            'search' => 'Search customers...',
            'metrics' => [
                $this->metric('Total Users', $totalUsers, $totalUsersBefore, 'count'),
                $this->metric('New Registrations', $newUsers->count(), $newUsersPrev, 'count'),
                $this->metric('Active Users', $activeUsers, $activeUsersPrev, 'count', $totalUsers
                    ? round($activeUsers / $totalUsers * 100, 1) . '% of all users ordered'
                    : null),
                $this->metric('Orders / Active User', round($ordersPerUser, 1), round($ordersPerUserPrev, 1), 'decimal'),
            ],
            'chartTitle' => 'User Growth Trajectory',
            'chartSub' => 'New registrations vs customers who placed an order',
            'chartSeries' => [
                [
                    'label' => 'New Users',
                    'format' => 'count',
                    'values' => $this->bucketize($buckets, $newUsers, fn ($c) => Carbon::parse($c->created_at), fn ($items) => $items->count()),
                ],
                [
                    'label' => 'Active Users',
                    'format' => 'count',
                    'values' => $this->bucketize($buckets, $current, fn ($o) => $o->report_at, fn ($items) => $items->pluck('customer_id')->filter()->unique()->count()),
                ],
            ],
            'tableTitle' => 'Customer Activity & Spend',
            'tableSub' => 'Orders and spend per customer in the selected range',
            'columns' => ['Customer', 'Orders', 'Total Spend', 'Avg. Order Value', 'Last Order', 'Status'],
            'rows' => $rows,
        ];
    }

    // =====================================================
    // RESTAURANTS
    // =====================================================

    private function restaurantsReport(array $ctx): array
    {
        ['start' => $start, 'end' => $end, 'current' => $current, 'previous' => $previous, 'buckets' => $buckets] = $ctx;

        $restaurants = Restaurant::query()->get(['id', 'restaurant_name', 'owner_name', 'status', 'is_online', 'created_at']);

        $approved = $restaurants->where('status', 'approved')->count();
        $approvedBefore = $restaurants
            ->where('status', 'approved')
            ->filter(fn ($r) => Carbon::parse($r->created_at)->lt($start))
            ->count();

        $activeNow = $current->pluck('restaurant_id')->unique()->count();
        $activePrev = $previous->pluck('restaurant_id')->unique()->count();

        $avgOrders = $activeNow ? $current->count() / $activeNow : 0;
        $avgOrdersPrev = $activePrev ? $previous->count() / $activePrev : 0;

        $ratingNow = RestaurantReview::whereBetween('created_at', [$start, $end])->avg('rating');
        $ratingPrev = RestaurantReview::whereBetween('created_at', [$ctx['prevStart'], $start])->avg('rating');

        $allRatings = RestaurantReview::query()
            ->selectRaw('restaurant_id, AVG(rating) as avg_rating, COUNT(*) as total')
            ->groupBy('restaurant_id')
            ->get()
            ->keyBy('restaurant_id');

        $byRestaurant = $current->groupBy('restaurant_id');

        $rows = $restaurants
            ->map(function ($restaurant) use ($byRestaurant, $allRatings) {
                $orders = $byRestaurant->get($restaurant->id, collect());
                $completion = $this->rate($orders->where('order_status', 'delivered')->count(), $orders->count());
                $rating = $allRatings->get($restaurant->id);

                return [
                    'sort' => $orders->count(),
                    'cells' => [
                        $restaurant->restaurant_name ?: ($restaurant->owner_name ?: 'Restaurant #' . $restaurant->id),
                        $this->cell($orders->count(), 'count'),
                        $this->cell($this->avgMinutes($orders, 'accepted_at', 'ready_for_pickup_at'), 'minutes'),
                        $this->cell($completion, 'percent'),
                        $rating
                            ? number_format((float) $rating->avg_rating, 1) . ' ★ (' . $rating->total . ')'
                            : '—',
                        $restaurant->is_online ? 'Online' : 'Offline',
                        $restaurant->status !== 'approved'
                            ? ucwords(str_replace('_', ' ', $restaurant->status))
                            : $this->performanceTier($orders->count(), $completion),
                    ],
                ];
            })
            ->sortByDesc('sort')
            ->pluck('cells')
            ->values();

        return [
            'search' => 'Search restaurants...',
            'metrics' => [
                $this->metric('Approved Partners', $approved, $approvedBefore, 'count', $restaurants->where('is_online', true)->count() . ' online right now'),
                $this->metric('Restaurants With Orders', $activeNow, $activePrev, 'count'),
                $this->metric('Avg. Orders / Restaurant', round($avgOrders, 1), round($avgOrdersPrev, 1), 'decimal'),
                $this->metric('Avg. Rating', $ratingNow !== null ? round((float) $ratingNow, 1) : null, $ratingPrev !== null ? round((float) $ratingPrev, 1) : null, 'rating', 'from reviews in this range'),
            ],
            'chartTitle' => 'Restaurant Order Activity',
            'chartSub' => 'Orders received vs orders delivered across partner kitchens',
            'chartSeries' => [
                [
                    'label' => 'Orders Received',
                    'format' => 'count',
                    'values' => $this->bucketize($buckets, $current, fn ($o) => $o->report_at, fn ($items) => $items->count()),
                ],
                [
                    'label' => 'Orders Delivered',
                    'format' => 'count',
                    'values' => $this->bucketize($buckets, $current, fn ($o) => $o->report_at, fn ($items) => $items->where('order_status', 'delivered')->count()),
                ],
            ],
            'tableTitle' => 'Restaurant Partner Performance',
            'tableSub' => 'Order volume, kitchen prep time, completion and rating',
            'columns' => ['Restaurant', 'Orders', 'Avg. Prep Time', 'Completion Rate', 'Rating', 'Availability', 'Status'],
            'rows' => $rows,
        ];
    }

    // =====================================================
    // DELIVERY
    // =====================================================

    private function deliveryReport(array $ctx): array
    {
        ['start' => $start, 'current' => $current, 'previous' => $previous, 'buckets' => $buckets] = $ctx;

        $riders = Rider::query()
            ->with('city:id,name')
            ->get();

        $approved = $riders->where('status', 'approved')->count();
        $approvedBefore = $riders
            ->where('status', 'approved')
            ->filter(fn ($r) => Carbon::parse($r->created_at)->lt($start))
            ->count();

        $delivered = $current->where('order_status', 'delivered');
        $deliveredPrev = $previous->where('order_status', 'delivered');

        $onTime = $this->onTimeRate($delivered);
        $onTimePrev = $this->onTimeRate($deliveredPrev);

        $byRider = $current->whereNotNull('rider_id')->groupBy('rider_id');

        $rows = $riders
            ->map(function ($rider) use ($byRider) {
                $orders = $byRider->get($rider->id, collect());
                $done = $orders->where('order_status', 'delivered');
                $onTime = $this->onTimeRate($done);

                return [
                    'sort' => $done->count(),
                    'cells' => [
                        $rider->name ?: 'Rider #' . $rider->id,
                        $rider->city?->name ?? '—',
                        $this->cell($done->count(), 'count'),
                        $this->cell($this->avgMinutes($done, 'ready_for_pickup_at', 'picked_up_at'), 'minutes'),
                        $this->cell($this->avgMinutes($done, 'picked_up_at', 'delivered_at'), 'minutes'),
                        $this->cell($onTime, 'percent'),
                        $rider->is_online ? 'Online' : 'Offline',
                        $rider->status !== 'approved'
                            ? ucfirst($rider->status)
                            : $this->performanceTier($done->count(), $onTime),
                    ],
                ];
            })
            ->sortByDesc('sort')
            ->pluck('cells')
            ->values();

        return [
            'search' => 'Search riders, cities...',
            'metrics' => [
                $this->metric('Approved Riders', $approved, $approvedBefore, 'count', $riders->where('is_online', true)->count() . ' online right now'),
                $this->metric('Completed Deliveries', $delivered->count(), $deliveredPrev->count(), 'count'),
                $this->metric('Avg. Delivery Time', $this->avgMinutes($delivered, 'picked_up_at', 'delivered_at'), $this->avgMinutes($deliveredPrev, 'picked_up_at', 'delivered_at'), 'minutes', 'pickup to doorstep', true),
                $this->metric('On-time Rate', $onTime, $onTimePrev, 'percent', 'delivered within ' . self::SLA_MINUTES . ' min of order'),
            ],
            'chartTitle' => 'Delivery Volume & SLA',
            'chartSub' => 'Completed trips vs trips delivered within ' . self::SLA_MINUTES . ' minutes',
            'chartSeries' => [
                [
                    'label' => 'Completed Trips',
                    'format' => 'count',
                    'values' => $this->bucketize($buckets, $delivered, fn ($o) => $o->report_at, fn ($items) => $items->count()),
                ],
                [
                    'label' => 'On-time Trips',
                    'format' => 'count',
                    'values' => $this->bucketize($buckets, $delivered, fn ($o) => $o->report_at, fn ($items) => $items->filter(fn ($o) => $this->isOnTime($o))->count()),
                ],
            ],
            'tableTitle' => 'Rider Performance',
            'tableSub' => 'Trips, pickup wait, transit time and SLA adherence per rider',
            'columns' => ['Rider', 'City', 'Completed Trips', 'Avg. Pickup Wait', 'Avg. Transit', 'On-time Rate', 'Availability', 'Status'],
            'rows' => $rows,
        ];
    }

    // =====================================================
    // ORDERS
    // =====================================================

    private function ordersReport(array $ctx): array
    {
        ['current' => $current, 'previous' => $previous, 'buckets' => $buckets] = $ctx;

        $valid = $current->where('order_status', '!=', 'cancelled');
        $validPrev = $previous->where('order_status', '!=', 'cancelled');

        $cancelRate = $this->rate($current->where('order_status', 'cancelled')->count(), $current->count());
        $cancelRatePrev = $this->rate($previous->where('order_status', 'cancelled')->count(), $previous->count());

        $restaurantNames = Restaurant::query()
            ->get(['id', 'restaurant_name', 'owner_name'])
            ->mapWithKeys(fn ($r) => [$r->id => $r->restaurant_name ?: ($r->owner_name ?: 'Restaurant #' . $r->id)]);

        $rows = $current
            ->groupBy('restaurant_id')
            ->map(function ($orders, $restaurantId) use ($restaurantNames) {
                $delivered = $orders->where('order_status', 'delivered');
                $cancelled = $orders->where('order_status', 'cancelled')->count();
                $completion = $this->rate($delivered->count(), $orders->count());

                return [
                    'sort' => $orders->count(),
                    'cells' => [
                        $restaurantNames->get($restaurantId, 'Restaurant #' . $restaurantId),
                        $this->cell($orders->count(), 'count'),
                        $this->cell($delivered->count(), 'count'),
                        $this->cell($cancelled, 'count'),
                        $this->cell($this->avgMinutes($orders, 'accepted_at', 'ready_for_pickup_at'), 'minutes'),
                        $this->cell($this->avgMinutes($delivered, 'placed_at', 'delivered_at'), 'minutes'),
                        $this->performanceTier($orders->count(), $completion),
                    ],
                ];
            })
            ->sortByDesc('sort')
            ->pluck('cells')
            ->values();

        return [
            'search' => 'Search restaurants...',
            'metrics' => [
                $this->metric('Total Orders', $current->count(), $previous->count(), 'count'),
                $this->metric('Delivered Orders', $current->where('order_status', 'delivered')->count(), $previous->where('order_status', 'delivered')->count(), 'count'),
                $this->metric('Avg. Order Value', $valid->count() ? $valid->sum('total_amount') / $valid->count() : 0, $validPrev->count() ? $validPrev->sum('total_amount') / $validPrev->count() : 0, 'currency', 'excluding cancelled orders'),
                $this->metric('Cancellation Rate', $cancelRate, $cancelRatePrev, 'percent', null, true),
            ],
            'chartTitle' => 'Order Volume & Fulfilment',
            'chartSub' => 'Orders placed vs orders successfully delivered',
            'chartSeries' => [
                [
                    'label' => 'Gross Orders',
                    'format' => 'count',
                    'values' => $this->bucketize($buckets, $current, fn ($o) => $o->report_at, fn ($items) => $items->count()),
                ],
                [
                    'label' => 'Fulfilled Orders',
                    'format' => 'count',
                    'values' => $this->bucketize($buckets, $current, fn ($o) => $o->report_at, fn ($items) => $items->where('order_status', 'delivered')->count()),
                ],
            ],
            'tableTitle' => 'Order Throughput by Restaurant',
            'tableSub' => 'Order volume, cancellations, prep time and end-to-end delivery time',
            'columns' => ['Restaurant', 'Total Orders', 'Delivered', 'Cancelled', 'Avg. Prep Time', 'Order to Door', 'Status'],
            'rows' => $rows,
        ];
    }

    // =====================================================
    // REVENUE & FINANCIAL
    // =====================================================

    private function revenueReport(array $ctx): array
    {
        ['start' => $start, 'end' => $end, 'prevStart' => $prevStart, 'current' => $current, 'previous' => $previous, 'buckets' => $buckets] = $ctx;

        $valid = $current->where('order_status', '!=', 'cancelled');
        $validPrev = $previous->where('order_status', '!=', 'cancelled');

        $riderPayouts = (float) RiderEarning::whereBetween('created_at', [$start, $end])->sum('amount');
        $riderPayoutsPrev = (float) RiderEarning::whereBetween('created_at', [$prevStart, $start])->sum('amount');

        $credited = $valid->where('earning_status', 'credited');
        $creditedPrev = $validPrev->where('earning_status', 'credited');

        $pending = $current->where('order_status', 'delivered')->where('earning_status', 'pending');
        $pendingPrev = $previous->where('order_status', 'delivered')->where('earning_status', 'pending');

        $restaurantNames = Restaurant::query()
            ->get(['id', 'restaurant_name', 'owner_name'])
            ->mapWithKeys(fn ($r) => [$r->id => $r->restaurant_name ?: ($r->owner_name ?: 'Restaurant #' . $r->id)]);

        $rows = $valid
            ->groupBy('restaurant_id')
            ->map(function ($orders, $restaurantId) use ($restaurantNames) {
                $gross = (float) $orders->sum('total_amount');
                $delivered = $orders->where('order_status', 'delivered');
                $settled = $delivered->where('earning_status', 'credited');
                $settlementRate = $this->rate($settled->count(), $delivered->count());

                return [
                    'sort' => $gross,
                    'cells' => [
                        $restaurantNames->get($restaurantId, 'Restaurant #' . $restaurantId),
                        $this->cell($gross, 'currency'),
                        $this->cell((float) $orders->sum('commission_amount'), 'currency'),
                        $this->cell((float) $settled->sum('restaurant_earning'), 'currency'),
                        $this->cell((float) $delivered->where('earning_status', 'pending')->sum('restaurant_earning'), 'currency'),
                        $this->cell($settlementRate, 'percent'),
                        $this->performanceTier($orders->count(), $settlementRate),
                    ],
                ];
            })
            ->sortByDesc('sort')
            ->pluck('cells')
            ->values();

        return [
            'search' => 'Search restaurants...',
            'metrics' => [
                $this->metric('Gross Revenue (GMV)', (float) $valid->sum('total_amount'), (float) $validPrev->sum('total_amount'), 'currency', 'excluding cancelled orders'),
                $this->metric('Platform Commission', (float) $valid->sum('commission_amount'), (float) $validPrev->sum('commission_amount'), 'currency'),
                $this->metric('Partner Payouts', (float) $credited->sum('restaurant_earning') + $riderPayouts, (float) $creditedPrev->sum('restaurant_earning') + $riderPayoutsPrev, 'currency', 'restaurants + riders'),
                $this->metric('Pending Settlements', (float) $pending->sum('restaurant_earning'), (float) $pendingPrev->sum('restaurant_earning'), 'currency', $pending->count() . ' delivered orders awaiting credit', true),
            ],
            'chartTitle' => 'Revenue & Settlement Performance',
            'chartSub' => 'Gross revenue vs restaurant earnings already credited',
            'chartSeries' => [
                [
                    'label' => 'Gross Revenue',
                    'format' => 'currency',
                    'values' => $this->bucketize($buckets, $valid, fn ($o) => $o->report_at, fn ($items) => round((float) $items->sum('total_amount'), 2)),
                ],
                [
                    'label' => 'Settled',
                    'format' => 'currency',
                    'values' => $this->bucketize($buckets, $credited, fn ($o) => $o->report_at, fn ($items) => round((float) $items->sum('restaurant_earning'), 2)),
                ],
            ],
            'tableTitle' => 'Financial Performance by Restaurant',
            'tableSub' => 'Revenue, commission, payouts and settlement progress',
            'columns' => ['Restaurant', 'Gross Revenue', 'Commission', 'Partner Payout', 'Pending', 'Settlement Rate', 'Status'],
            'rows' => $rows,
        ];
    }

    // =====================================================
    // HELPERS
    // =====================================================

    private function resolveRange(string $range): array
    {
        $end = now();

        return match ($range) {
            '7d' => [$end->copy()->subDays(7), $end, 'Last 7 Days'],
            '90d' => [$end->copy()->subDays(90), $end, 'Last 90 Days'],
            'year' => [$end->copy()->startOfYear(), $end, 'This Year'],
            default => [$end->copy()->subDays(30), $end, 'Last 30 Days'],
        };
    }

    /**
     * Split the range into equal chart buckets.
     */
    private function buckets(Carbon $start, Carbon $end): array
    {
        $step = abs($end->getTimestamp() - $start->getTimestamp()) / self::BUCKETS;

        return collect(range(0, self::BUCKETS - 1))
            ->map(function ($index) use ($start, $step) {
                $from = $start->copy()->addSeconds((int) round($step * $index));
                $to = $start->copy()->addSeconds((int) round($step * ($index + 1)));

                return ['from' => $from, 'to' => $to];
            })
            ->all();
    }

    /**
     * Group items into the chart buckets and reduce each bucket to a value.
     * Returns [{label, start, end, value}] for the frontend.
     */
    private function bucketize(array $buckets, Collection $items, callable $timeOf, callable $reduce): array
    {
        $last = count($buckets) - 1;

        return collect($buckets)
            ->map(function ($bucket, $index) use ($items, $timeOf, $reduce, $last) {
                $inBucket = $items->filter(function ($item) use ($bucket, $timeOf, $index, $last) {
                    $at = $timeOf($item);

                    return $at->gte($bucket['from'])
                        && ($index === $last ? $at->lte($bucket['to']) : $at->lt($bucket['to']));
                });

                return [
                    'start' => $bucket['from']->toDateTimeString(),
                    'end' => $bucket['to']->toDateTimeString(),
                    'value' => $reduce($inBucket),
                ];
            })
            ->all();
    }

    private function metric(string $label, $value, $previous, string $format, ?string $sub = null, bool $lowerIsBetter = false): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'previous' => $previous,
            'format' => $format,
            'sub' => $sub,
            'lowerIsBetter' => $lowerIsBetter,
        ];
    }

    private function cell($value, string $format): array
    {
        return ['value' => $value, 'format' => $format];
    }

    private function rate(int $part, int $total): ?float
    {
        return $total ? round($part / $total * 100, 1) : null;
    }

    private function minutesBetween($from, $to): ?float
    {
        if (! $from || ! $to) {
            return null;
        }

        $seconds = Carbon::parse($to)->getTimestamp() - Carbon::parse($from)->getTimestamp();

        return $seconds >= 0 ? $seconds / 60 : null;
    }

    private function avgMinutes(Collection $orders, string $fromColumn, string $toColumn): ?float
    {
        $minutes = $orders
            ->map(fn ($order) => $this->minutesBetween($order->{$fromColumn}, $order->{$toColumn}))
            ->filter(fn ($value) => $value !== null);

        return $minutes->isEmpty() ? null : round($minutes->avg(), 1);
    }

    private function isOnTime($order): bool
    {
        $minutes = $this->minutesBetween($order->placed_at ?? $order->created_at, $order->delivered_at);

        return $minutes !== null && $minutes <= self::SLA_MINUTES;
    }

    private function onTimeRate(Collection $delivered): ?float
    {
        return $this->rate($delivered->filter(fn ($o) => $this->isOnTime($o))->count(), $delivered->count());
    }

    /**
     * Activity tier from a count, using [high, optimal, stable] thresholds.
     */
    private function tier(int $count, array $thresholds): string
    {
        [$high, $optimal, $stable] = $thresholds;

        return match (true) {
            $count >= $high => 'High Yield',
            $count >= $optimal => 'Optimal',
            $count >= $stable => 'Stable',
            default => 'Inactive',
        };
    }

    /**
     * Tier from a success rate (completion / on-time / settlement).
     */
    private function performanceTier(int $orders, ?float $rate): string
    {
        if ($orders === 0 || $rate === null) {
            return 'Inactive';
        }

        return match (true) {
            $rate >= 95 => 'High Yield',
            $rate >= 85 => 'Optimal',
            $rate >= 70 => 'Stable',
            default => 'Attention',
        };
    }
}
