<?php

namespace App\Services\Restaurant;

use App\Models\Restaurant;
use App\Models\RestaurantDocument;
use App\Models\RestaurantNotification;
use App\Models\RestaurantWalletTransaction;
use App\Models\RestaurantWeeklySettlement;
use Carbon\Carbon;

/**
 * Restaurant weekly settlement.
 *
 * A cycle runs Monday 00:00 to the next Monday 00:00 and is paid out on the
 * Tuesday morning after it closes (config/settlement.php payout_time).
 * Every wallet entry is linked to the cycle it was made in, so the payout is
 * the wallet balance belonging to the closed cycle (and earlier unpaid ones).
 *
 * The payout needs a UPI ID in the restaurant documents; without one it
 * fails, the restaurant is notified and the balance carries to next week.
 *
 * Callers wrap these methods in a DB transaction.
 */
class RestaurantSettlementService
{
    /**
     * Cycle containing $at: week_start (Mon), week_end (Sun), payout_date (Tue)
     */
    public function cycleFor(?Carbon $at = null): array
    {
        $start = ($at ?? now())->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

        return [
            'week_start' => $start->copy(),
            'week_end' => $start->copy()->addDays(6),
            'payout_date' => $start->copy()->addDays(8),
        ];
    }

    /**
     * The restaurant's settlement row for the cycle containing $at
     */
    public function settlementFor(Restaurant $restaurant, ?Carbon $at = null): RestaurantWeeklySettlement
    {
        $cycle = $this->cycleFor($at);

        return RestaurantWeeklySettlement::firstOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'week_start' => $cycle['week_start']->toDateString(),
                'week_end' => $cycle['week_end']->toDateString(),
            ],
            [
                'payout_date' => $cycle['payout_date']->toDateString(),
                'status' => 'pending',
            ]
        );
    }

    /**
     * Credit or debit the restaurant wallet and write the history entry,
     * linked to the current cycle unless a settlement is given.
     */
    public function record(
        Restaurant $restaurant,
        string $type,
        float $amount,
        string $title,
        ?string $description = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?RestaurantWeeklySettlement $settlement = null,
    ): RestaurantWalletTransaction {
        $amount = round($amount, 2);
        $settlement ??= $this->settlementFor($restaurant);

        if ($type === 'credit') {
            $restaurant->increment('wallet_balance', $amount);
        } else {
            $restaurant->decrement('wallet_balance', $amount);
        }

        $restaurant->refresh();

        return RestaurantWalletTransaction::create([
            'restaurant_id' => $restaurant->id,
            'settlement_id' => $settlement->id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $restaurant->wallet_balance,
            'title' => $title,
            'description' => $description,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }

    /**
     * Wallet balance that belongs to this cycle and earlier ones:
     * the current balance minus whatever later cycles have added.
     */
    public function payableFor(RestaurantWeeklySettlement $settlement, Restaurant $restaurant): float
    {
        $laterIds = RestaurantWeeklySettlement::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereDate('week_start', '>', $settlement->week_start)
            ->pluck('id');

        $laterNet = $laterIds->isEmpty() ? 0.0 : (float) RestaurantWalletTransaction::query()
            ->whereIn('settlement_id', $laterIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) as net")
            ->value('net');

        $wallet = (float) $restaurant->wallet_balance;

        return round(max(min($wallet - $laterNet, $wallet), 0), 2);
    }

    /**
     * UPI ID the payout goes to, from the restaurant documents
     */
    public function payoutUpi(Restaurant $restaurant): ?string
    {
        $upi = RestaurantDocument::where('restaurant_id', $restaurant->id)->value('upi_id');

        return $upi ? trim($upi) : null;
    }

    /**
     * Pay out a settlement.
     *
     * auto:   needs a UPI ID in the documents, otherwise fails, notifies the
     *         restaurant and the balance carries into next week's payout
     * manual: admin paid the restaurant outside the app
     */
    public function payout(RestaurantWeeklySettlement $settlement, bool $manual = false): RestaurantWeeklySettlement
    {
        $restaurant = Restaurant::lockForUpdate()->find($settlement->restaurant_id);

        if (!$restaurant) {
            $settlement->update([
                'status' => 'failed',
                'remarks' => 'Restaurant not found.',
            ]);

            return $settlement;
        }

        $period = $this->periodLabel($settlement);

        $payable = $this->payableFor($settlement, $restaurant);

        // Snapshot the cycle once, on the first payout attempt
        if ($settlement->closing_balance === null) {
            $this->snapshot($settlement, $payable);
        }

        if ($payable <= 0) {
            $settlement->fill([
                'status' => 'successful',
                'processed_at' => now(),
                'remarks' => 'No balance to pay out.',
            ])->save();

            $this->closeEarlierFailed($settlement);

            return $settlement;
        }

        $upi = $this->payoutUpi($restaurant);

        if (!$manual && !$upi) {
            $alreadyFailed = $settlement->status === 'failed';

            $settlement->fill([
                'status' => 'failed',
                'remarks' => 'No UPI ID added in restaurant documents. Balance carried to the next payout.',
            ])->save();

            // Notify once per settlement
            if (!$alreadyFailed) {
                RestaurantNotification::create([
                    'restaurant_id' => $restaurant->id,
                    'type' => 'payment',
                    'title' => 'Add UPI ID to receive your payout',
                    'message' => 'Your weekly payout of ₹' . number_format($payable, 2) . ' for ' . $period
                        . ' could not be sent because no UPI ID is added. Please add your UPI ID in documents. '
                        . 'The amount stays in your wallet and will be paid in the next payout.',
                    'reference_type' => 'restaurant_settlement',
                    'reference_id' => $settlement->id,
                    'is_read' => false,
                ]);
            }

            return $settlement;
        }

        /*
         * REAL UPI PAYOUT WILL BE CONNECTED LATER.
         *
         * For now the payout is recorded as a wallet debit.
         */

        $this->record(
            $restaurant,
            'debit',
            $payable,
            $manual ? 'Manual Payout' : 'Weekly Payout',
            ($manual ? 'Manual payout' : 'Weekly payout') . ' for ' . $period . ($upi ? ' to UPI ' . $upi : '') . '.',
            'restaurant_settlement',
            $settlement->id,
            $settlement,
        );

        $settlement->fill([
            'status' => 'successful',
            'payout_amount' => round((float) $settlement->payout_amount + $payable, 2),
            'payout_reference' => ($manual ? 'MANUAL' : 'TEST-UPI') . '-R' . $settlement->id . '-' . time(),
            'processed_at' => now(),
            'remarks' => $manual
                ? 'Marked as paid by admin.'
                : 'Weekly payout recorded to UPI ' . $upi . ' (gateway not integrated yet).',
        ])->save();

        $this->closeEarlierFailed($settlement);

        RestaurantNotification::create([
            'restaurant_id' => $restaurant->id,
            'type' => 'payment',
            'title' => 'Weekly payout processed',
            'message' => '₹' . number_format($payable, 2) . ' for ' . $period . ' has been settled'
                . ($upi ? ' to ' . $upi : '') . '.',
            'reference_type' => 'restaurant_settlement',
            'reference_id' => $settlement->id,
            'is_read' => false,
        ]);

        return $settlement;
    }

    public function periodLabel(RestaurantWeeklySettlement $settlement): string
    {
        return $settlement->week_start->format('d M') . ' – ' . $settlement->week_end->format('d M Y');
    }

    /**
     * Credits / debits made during the cycle and the balance it closed with
     */
    private function snapshot(RestaurantWeeklySettlement $settlement, float $closingBalance): void
    {
        $cutoff = $settlement->week_end->copy()->addDay()->startOfDay();

        $activity = RestaurantWalletTransaction::query()
            ->where('settlement_id', $settlement->id)
            ->where('created_at', '<', $cutoff)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as credit")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as debit")
            ->first();

        $credit = round((float) $activity->credit, 2);
        $debit = round((float) $activity->debit, 2);

        $settlement->fill([
            'total_credit' => $credit,
            'total_debit' => $debit,
            'closing_balance' => $closingBalance,
            'opening_balance' => round($closingBalance - $credit + $debit, 2),
        ]);
    }

    /**
     * Earlier failed cycles were carried into this payout
     */
    private function closeEarlierFailed(RestaurantWeeklySettlement $settlement): void
    {
        RestaurantWeeklySettlement::query()
            ->where('restaurant_id', $settlement->restaurant_id)
            ->where('status', 'failed')
            ->whereDate('week_start', '<', $settlement->week_start)
            ->update([
                'status' => 'successful',
                'payout_reference' => $settlement->payout_reference,
                'processed_at' => now(),
                'remarks' => 'Carried forward and paid with the payout for ' . $this->periodLabel($settlement) . '.',
            ]);
    }
}
