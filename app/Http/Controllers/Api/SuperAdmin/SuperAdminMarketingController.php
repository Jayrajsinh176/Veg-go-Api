<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\CouponRedemption;
use App\Models\RestaurantAdvertisement;
use App\Models\RestaurantAdvertisementEvent;
use App\Models\RestaurantOffer;
use App\Models\RestaurantOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Advertisements page (Marketing Overview): summary cards and the
 * impressions / clicks chart. Combines ads, offers, coupons and orders, so
 * it reads them directly; ad list and actions stay on
 * SuperAdminAdvertisementController.
 *
 * Every endpoint takes ?range=7d|30d|90d|custom (&from=&to= for custom) and
 * compares against the same-length period just before it.
 */
class SuperAdminMarketingController extends Controller
{
    private const RANGE_DAYS = ['7d' => 7, '30d' => 30, '90d' => 90];

    private const MAX_CUSTOM_DAYS = 366;

    public function overview(Request $request)
    {
        $period = $this->period($request);
        [$from, $to, $prevFrom, $prevTo] = [$period['from'], $period['to'], $period['previous_from'], $period['previous_to']];
        $now = now();

        $started = fn ($start, $end) => RestaurantAdvertisement::query()
            ->whereNotIn('status', [RestaurantAdvertisement::STATUS_DRAFT, RestaurantAdvertisement::STATUS_PENDING])
            ->where('start_at', '>=', $start)
            ->where('start_at', '<', $end)
            ->where('start_at', '<=', $now)
            ->count();

        $newOffers = fn ($start, $end) => RestaurantOffer::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();

        $couponUsage = fn ($start, $end) => CouponRedemption::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('COALESCE(SUM(discount_amount), 0) as amount, COUNT(*) as redemptions')
            ->first();

        $events = fn ($start, $end) => RestaurantAdvertisementEvent::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('event, COUNT(*) as total')
            ->groupBy('event')
            ->pluck('total', 'event');

        $coupons = $couponUsage($from, $to);
        $prevCoupons = $couponUsage($prevFrom, $prevTo);

        $currentEvents = $events($from, $to);
        $previousEvents = $events($prevFrom, $prevTo);
        $impressions = (int) ($currentEvents[RestaurantAdvertisementEvent::EVENT_IMPRESSION] ?? 0);
        $clicks = (int) ($currentEvents[RestaurantAdvertisementEvent::EVENT_CLICK] ?? 0);
        $prevImpressions = (int) ($previousEvents[RestaurantAdvertisementEvent::EVENT_IMPRESSION] ?? 0);
        $prevClicks = (int) ($previousEvents[RestaurantAdvertisementEvent::EVENT_CLICK] ?? 0);

        $conversion = $this->promoConversion($from, $to);
        $prevConversion = $this->promoConversion($prevFrom, $prevTo);

        return response()->json([
            'success' => true,
            'message' => 'Marketing overview fetched successfully.',
            'data' => [
                'range' => $this->formatPeriod($period),
                'cards' => [
                    'active_campaigns' => [
                        'value' => RestaurantAdvertisement::query()
                            ->where('status', RestaurantAdvertisement::STATUS_ACTIVE)
                            ->where('start_at', '<=', $now)
                            ->where('end_at', '>', $now)
                            ->count(),
                        'started' => $started($from, $to),
                        'previous_started' => $started($prevFrom, $prevTo),
                    ],
                    'scheduled_campaigns' => [
                        'value' => RestaurantAdvertisement::query()
                            ->where('status', RestaurantAdvertisement::STATUS_ACTIVE)
                            ->where('start_at', '>', $now)
                            ->count(),
                    ],
                    'active_offers' => [
                        'value' => RestaurantOffer::query()->running()->count(),
                        'new' => $newOffers($from, $to),
                        'previous_new' => $newOffers($prevFrom, $prevTo),
                    ],
                    'coupon_usage' => [
                        'amount' => round((float) $coupons->amount, 2),
                        'redemptions' => (int) $coupons->redemptions,
                        'previous_amount' => round((float) $prevCoupons->amount, 2),
                        'change_percent' => $this->changePercent((float) $coupons->amount, (float) $prevCoupons->amount),
                    ],
                    'impressions' => [
                        'value' => $impressions,
                        'previous' => $prevImpressions,
                        'change_percent' => $this->changePercent($impressions, $prevImpressions),
                    ],
                    'clicks' => [
                        'value' => $clicks,
                        'previous' => $prevClicks,
                        'change_percent' => $this->changePercent($clicks, $prevClicks),
                        'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
                    ],
                    // Share of non-cancelled orders that used a coupon or an item offer
                    'conversion' => [
                        'rate' => $conversion['rate'],
                        'promo_orders' => $conversion['promo_orders'],
                        'total_orders' => $conversion['total_orders'],
                        'previous_rate' => $prevConversion['rate'],
                        'change_points' => $conversion['rate'] !== null && $prevConversion['rate'] !== null
                            ? round($conversion['rate'] - $prevConversion['rate'], 2)
                            : null,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Impressions and clicks per day (up to 31 days), week (up to 186 days)
     * or month.
     */
    public function performance(Request $request)
    {
        $period = $this->period($request);
        $from = $period['from'];
        $to = $period['to'];
        $unit = $period['days'] <= 31 ? 'day' : ($period['days'] <= 186 ? 'week' : 'month');

        $buckets = [];
        $cursor = $from->copy();

        while ($cursor->lt($to)) {
            $end = match ($unit) {
                'day' => $cursor->copy()->addDay(),
                'week' => $cursor->copy()->addWeek(),
                'month' => $cursor->copy()->addMonthNoOverflow()->startOfMonth(),
            };
            $end = $end->gt($to) ? $to->copy() : $end;

            $buckets[] = ['start' => $cursor->copy(), 'end' => $end, 'impressions' => 0, 'clicks' => 0];
            $cursor = $end;
        }

        $rows = RestaurantAdvertisementEvent::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->selectRaw('DATE(created_at) as day, event, COUNT(*) as total')
            ->groupBy('day', 'event')
            ->get();

        foreach ($rows as $row) {
            $day = Carbon::parse($row->day);
            $key = $row->event === RestaurantAdvertisementEvent::EVENT_CLICK ? 'clicks' : 'impressions';

            foreach ($buckets as $index => $bucket) {
                if ($day->gte($bucket['start']) && $day->lt($bucket['end'])) {
                    $buckets[$index][$key] += (int) $row->total;
                    break;
                }
            }
        }

        $points = collect($buckets)->map(fn ($bucket) => [
            'label' => $unit === 'month' ? $bucket['start']->format('M Y') : $bucket['start']->format('d M'),
            'start' => $bucket['start']->toDateString(),
            'end' => $bucket['end']->copy()->subDay()->toDateString(),
            'impressions' => $bucket['impressions'],
            'clicks' => $bucket['clicks'],
            'ctr' => $bucket['impressions'] > 0 ? round($bucket['clicks'] / $bucket['impressions'] * 100, 2) : null,
        ])->values();

        $impressions = $points->sum('impressions');
        $clicks = $points->sum('clicks');

        return response()->json([
            'success' => true,
            'message' => 'Marketing performance fetched successfully.',
            'data' => [
                'range' => $this->formatPeriod($period),
                'unit' => $unit,
                'points' => $points,
                'totals' => [
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
                ],
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * [from, to) for the chosen range, plus the same-length period before it.
     */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'range' => ['nullable', Rule::in([...array_keys(self::RANGE_DAYS), 'custom'])],
            'from' => ['required_if:range,custom', 'nullable', 'date'],
            'to' => ['required_if:range,custom', 'nullable', 'date', 'after_or_equal:from', 'before_or_equal:today'],
        ]);

        $range = $validated['range'] ?? '30d';

        if ($range === 'custom') {
            $from = Carbon::parse($validated['from'])->startOfDay();
            $to = Carbon::parse($validated['to'])->addDay()->startOfDay();

            if ($from->diffInDays($to) > self::MAX_CUSTOM_DAYS) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Custom range can be at most ' . self::MAX_CUSTOM_DAYS . ' days.',
                    'errors' => ['to' => ['Custom range can be at most ' . self::MAX_CUSTOM_DAYS . ' days.']],
                ], 422));
            }
        } else {
            $to = now()->addDay()->startOfDay();
            $from = $to->copy()->subDays(self::RANGE_DAYS[$range]);
        }

        $days = (int) $from->diffInDays($to);

        return [
            'key' => $range,
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'previous_from' => $from->copy()->subDays($days),
            'previous_to' => $from->copy(),
        ];
    }

    private function formatPeriod(array $period): array
    {
        return [
            'key' => $period['key'],
            'days' => $period['days'],
            'from' => $period['from']->toDateString(),
            'to' => $period['to']->copy()->subDay()->toDateString(),
            'previous_from' => $period['previous_from']->toDateString(),
            'previous_to' => $period['previous_to']->copy()->subDay()->toDateString(),
        ];
    }

    private function promoConversion(Carbon $from, Carbon $to): array
    {
        $orders = RestaurantOrder::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->where('order_status', '!=', 'cancelled');

        $total = (clone $orders)->count();

        $promo = (clone $orders)
            ->where(fn ($q) => $q
                ->whereExists(fn ($s) => $s->selectRaw('1')
                    ->from('coupon_redemptions')
                    ->whereColumn('coupon_redemptions.order_id', 'restaurant_orders.id'))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')
                    ->from('restaurant_order_items')
                    ->whereColumn('restaurant_order_items.order_id', 'restaurant_orders.id')
                    ->whereNotNull('restaurant_order_items.offer_id')))
            ->count();

        return [
            'rate' => $total > 0 ? round($promo / $total * 100, 2) : null,
            'promo_orders' => $promo,
            'total_orders' => $total,
        ];
    }

    private function changePercent(float|int $current, float|int $previous): ?float
    {
        if ($previous == 0) {
            return $current == 0 ? 0.0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
