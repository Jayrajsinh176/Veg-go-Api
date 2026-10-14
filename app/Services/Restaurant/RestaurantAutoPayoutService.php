<?php

namespace App\Services\Restaurant;

use App\Models\Restaurant;
use App\Models\RestaurantWeeklySettlement;
use App\Support\SettlementSchedule;
use Illuminate\Support\Facades\DB;

class RestaurantAutoPayoutService
{
    public function __construct(
        protected RestaurantSettlementService $settlementService
    ) {
    }

    /**
     * Pays out every Monday-to-Monday cycle that has closed, from the
     * Tuesday-morning payout time onwards
     */
    public function processDueSettlements(): int
    {
        $this->openClosedCycle();

        $settlements = RestaurantWeeklySettlement::where('status', 'pending')
            ->whereDate('payout_date', '<=', SettlementSchedule::dueDate())
            ->orderBy('week_start')
            ->orderBy('id')
            ->get();

        $processedCount = 0;

        foreach ($settlements as $settlement) {

            DB::transaction(function () use ($settlement, &$processedCount) {

                $settlement = RestaurantWeeklySettlement::where('id', $settlement->id)
                    ->lockForUpdate()
                    ->first();

                if (!$settlement || $settlement->status !== 'pending') {
                    return;
                }

                // Admin has put this restaurant's payouts on hold
                $onHold = RestaurantWeeklySettlement::where('restaurant_id', $settlement->restaurant_id)
                    ->where('status', 'processing')
                    ->exists();

                if ($onHold) {
                    return;
                }

                $settlement = $this->settlementService->payout($settlement);

                if ($settlement->status === 'successful') {
                    $processedCount++;
                }
            });
        }

        return $processedCount;
    }

    /**
     * Restaurants whose earlier payout failed (e.g. no UPI ID) but who had
     * no activity in the cycle that just closed still get a row for it, so
     * the carried balance is retried this Tuesday.
     */
    private function openClosedCycle(): void
    {
        $cycle = $this->settlementService->cycleFor(now()->subWeek());

        Restaurant::query()
            ->where('wallet_balance', '>', 0)
            ->whereHas('weeklySettlements', fn ($query) => $query->where('status', 'failed'))
            ->whereNotExists(function ($query) use ($cycle) {
                $query->select(DB::raw(1))
                    ->from('restaurant_weekly_settlements')
                    ->whereColumn('restaurant_weekly_settlements.restaurant_id', 'restaurants.id')
                    ->whereDate('restaurant_weekly_settlements.week_start', $cycle['week_start']->toDateString());
            })
            ->each(fn (Restaurant $restaurant) => $this->settlementService->settlementFor($restaurant, $cycle['week_start']));
    }
}
