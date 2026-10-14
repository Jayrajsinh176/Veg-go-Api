<?php

namespace App\Services\Rider;

use App\Models\Rider;
use App\Models\RiderWeeklySettlement;
use App\Support\SettlementSchedule;
use Illuminate\Support\Facades\DB;

class RiderAutoPayoutService
{
    public function __construct(
        protected RiderSettlementService $settlementService
    ) {
    }

    /**
     * Pays out every Wednesday-to-Wednesday cycle that has closed, from the
     * Thursday-morning payout time onwards
     */
    public function processDueSettlements(): int
    {
        $this->openClosedCycle();

        $settlements = RiderWeeklySettlement::where('status', 'pending')
            ->whereDate('payout_date', '<=', SettlementSchedule::dueDate())
            ->orderBy('week_start')
            ->orderBy('id')
            ->get();

        $processedCount = 0;

        foreach ($settlements as $settlement) {

            DB::transaction(function () use ($settlement, &$processedCount) {

                $settlement = RiderWeeklySettlement::where('id', $settlement->id)
                    ->lockForUpdate()
                    ->first();

                if (!$settlement || $settlement->status !== 'pending') {
                    return;
                }

                // Admin has put this rider's payouts on hold
                $onHold = RiderWeeklySettlement::where('rider_id', $settlement->rider_id)
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
     * Riders whose earlier payout failed but who had no activity in the cycle
     * that just closed still get a settlement row for it, so the carried
     * balance is retried this Wednesday.
     */
    private function openClosedCycle(): void
    {
        $cycle = $this->settlementService->cycleFor(now()->subWeek());

        Rider::query()
            ->where('wallet_balance', '>', 0)
            ->whereHas('weeklySettlements', fn ($query) => $query->where('status', 'failed'))
            ->whereNotExists(function ($query) use ($cycle) {
                $query->select(DB::raw(1))
                    ->from('rider_weekly_settlements')
                    ->whereColumn('rider_weekly_settlements.rider_id', 'riders.id')
                    ->whereDate('rider_weekly_settlements.week_start', $cycle['week_start']->toDateString());
            })
            ->each(fn (Rider $rider) => $this->settlementService->settlementFor($rider, $cycle['week_start']));
    }
}
