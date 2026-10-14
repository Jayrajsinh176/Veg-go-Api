<?php

namespace App\Services\Rider;

use App\Models\Rider;
use App\Models\RiderBankDetail;
use App\Models\RiderCodSettlement;
use App\Models\RiderUpiDetail;
use App\Models\RiderWalletTransaction;
use App\Models\RiderWeeklySettlement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Rider weekly settlement.
 *
 * A cycle runs Wednesday 00:00 to the next Wednesday 00:00 and is paid out
 * on the Thursday morning after it closes (config/settlement.php payout_time). Every wallet entry is linked to the cycle it
 * was made in, so the payout is the wallet balance belonging to the closed
 * cycle (and any earlier unpaid ones). Pending COD is never deducted at
 * payout: the rider settles it with the Settle COD button, or pays admin in cash.
 *
 * Callers wrap these methods in a DB transaction.
 */
class RiderSettlementService
{
    /**
     * Cycle containing $at: week_start (Wed), week_end (Tue), payout_date
     * (Thursday after the Wednesday cut-off, paid at config settlement.payout_time)
     */
    public function cycleFor(?Carbon $at = null): array
    {
        $at = ($at ?? now())->copy();

        $start = $at->isWednesday()
            ? $at->startOfDay()
            : $at->previous(Carbon::WEDNESDAY);

        return [
            'week_start' => $start->copy(),
            'week_end' => $start->copy()->addDays(6),
            'payout_date' => $start->copy()->addDays(8),
        ];
    }

    /**
     * The rider's settlement row for the cycle containing $at
     */
    public function settlementFor(Rider $rider, ?Carbon $at = null): RiderWeeklySettlement
    {
        $cycle = $this->cycleFor($at);

        return RiderWeeklySettlement::firstOrCreate(
            [
                'rider_id' => $rider->id,
                'week_start' => $cycle['week_start']->toDateString(),
                'week_end' => $cycle['week_end']->toDateString(),
            ],
            [
                'total_amount' => 0,
                'payout_date' => $cycle['payout_date']->toDateString(),
                'status' => 'pending',
            ]
        );
    }

    /**
     * Credit or debit the rider wallet and write the history entry,
     * linked to the current cycle unless a settlement is given.
     *
     * $isEarning: also counts towards the cycle's earnings (total_amount).
     */
    public function record(
        Rider $rider,
        string $type,
        float $amount,
        string $title,
        ?string $description = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?RiderWeeklySettlement $settlement = null,
        bool $isEarning = false,
    ): RiderWalletTransaction {
        $amount = round($amount, 2);
        $settlement ??= $this->settlementFor($rider);

        if ($type === 'credit') {
            $rider->increment('wallet_balance', $amount);
        } else {
            $rider->decrement('wallet_balance', $amount);
        }

        $rider->refresh();

        if ($isEarning) {
            $settlement->increment('total_amount', $type === 'credit' ? $amount : -$amount);
        }

        return RiderWalletTransaction::create([
            'rider_id' => $rider->id,
            'settlement_id' => $settlement->id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $rider->wallet_balance,
            'title' => $title,
            'description' => $description,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }

    /**
     * Reduce the rider's pending COD.
     *
     * wallet: amount is debited from the wallet (rider app / weekly payout)
     * cash:   rider handed the cash to admin, wallet is untouched
     */
    public function settleCod(
        Rider $rider,
        float $amount,
        string $method,
        string $source,
        ?RiderWeeklySettlement $settlement = null,
        ?Model $settledBy = null,
        ?string $remarks = null,
    ): RiderCodSettlement {
        $amount = round($amount, 2);

        $transaction = null;

        if ($method === 'wallet') {
            $transaction = $this->record(
                $rider,
                'debit',
                $amount,
                $source === 'auto_payout' ? 'COD Adjusted in Weekly Payout' : 'COD Settlement',
                'Settlement of collected COD amount.',
                'cod_settlement',
                null,
                $settlement,
            );
        }

        $rider->update([
            'pending_cod' => max(round((float) $rider->pending_cod - $amount, 2), 0),
        ]);

        $codSettlement = RiderCodSettlement::create([
            'rider_id' => $rider->id,
            'amount' => $amount,
            'method' => $method,
            'source' => $source,
            'pending_cod_after' => $rider->pending_cod,
            'settlement_id' => $settlement?->id ?? $transaction?->settlement_id,
            'wallet_transaction_id' => $transaction?->id,
            'settled_by_type' => $settledBy ? class_basename($settledBy) : null,
            'settled_by_id' => $settledBy?->getKey(),
            'remarks' => $remarks,
        ]);

        $transaction?->update(['reference_id' => $codSettlement->id]);

        return $codSettlement;
    }

    /**
     * Wallet balance that belongs to this cycle and earlier ones:
     * the current balance minus whatever later cycles have added.
     */
    public function payableFor(RiderWeeklySettlement $settlement, Rider $rider): float
    {
        $laterIds = RiderWeeklySettlement::query()
            ->where('rider_id', $rider->id)
            ->whereDate('week_start', '>', $settlement->week_start)
            ->pluck('id');

        $laterNet = $laterIds->isEmpty() ? 0.0 : (float) RiderWalletTransaction::query()
            ->whereIn('settlement_id', $laterIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) as net")
            ->value('net');

        $wallet = (float) $rider->wallet_balance;

        return round(max(min($wallet - $laterNet, $wallet), 0), 2);
    }

    /**
     * Pay out a settlement.
     *
     * auto:   needs a verified UPI or bank account, otherwise fails and the
     *         balance carries into the next Wednesday's payout
     * manual: admin paid the rider outside the app
     */
    public function payout(RiderWeeklySettlement $settlement, bool $manual = false): RiderWeeklySettlement
    {
        $rider = Rider::lockForUpdate()->find($settlement->rider_id);

        if (!$rider) {
            $settlement->update([
                'status' => 'failed',
                'remarks' => 'Rider not found.',
            ]);

            return $settlement;
        }

        $period = $settlement->week_start->format('d M') . ' - ' . $settlement->week_end->format('d M Y');

        $payable = $this->payableFor($settlement, $rider);

        // Snapshot the cycle once, on the first payout attempt
        if ($settlement->closing_balance === null) {
            $this->snapshot($settlement, $payable);
        }

        // Pending COD is not touched here: the rider settles it from the app
        // (Settle COD) or pays admin in cash

        if ($payable <= 0) {
            $settlement->fill([
                'status' => 'successful',
                'processed_at' => now(),
                'remarks' => 'No balance to pay out.',
            ])->save();

            $this->closeEarlierFailed($settlement);

            return $settlement;
        }

        $method = 'MANUAL';

        if (!$manual) {
            $hasUpi = RiderUpiDetail::where('rider_id', $rider->id)->where('is_verified', true)->exists();
            $hasBank = RiderBankDetail::where('rider_id', $rider->id)->where('is_verified', true)->exists();

            if (!$hasUpi && !$hasBank) {
                $settlement->fill([
                    'status' => 'failed',
                    'remarks' => 'No verified UPI or bank account found. Balance carried to the next payout.',
                ])->save();

                return $settlement;
            }

            $method = $hasUpi ? 'UPI' : 'BANK';
        }

        /*
         * REAL BANK/UPI PAYMENT WILL BE CONNECTED LATER.
         *
         * For now, simulate a successful payout.
         */

        $this->record(
            $rider,
            'debit',
            $payable,
            $manual ? 'Manual Payout' : 'Automatic Weekly Payout',
            ($manual ? 'Manual' : 'Automatic') . ' payout for ' . $period,
            'weekly_settlement',
            $settlement->id,
            $settlement,
        );

        $settlement->fill([
            'status' => 'successful',
            'payout_amount' => round((float) $settlement->payout_amount + $payable, 2),
            'payout_reference' => ($manual ? 'MANUAL' : 'TEST-' . $method) . '-' . $settlement->id . '-' . time(),
            'processed_at' => now(),
            'remarks' => $manual
                ? 'Marked as paid by admin.'
                : 'Test automatic weekly payout processed successfully via ' . $method . '.',
        ])->save();

        $this->closeEarlierFailed($settlement);

        return $settlement;
    }

    /**
     * Credits / debits made during the cycle and the balance it closed with
     */
    private function snapshot(RiderWeeklySettlement $settlement, float $closingBalance): void
    {
        $cutoff = $settlement->week_end->copy()->addDay()->startOfDay();

        $activity = RiderWalletTransaction::query()
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
    private function closeEarlierFailed(RiderWeeklySettlement $settlement): void
    {
        RiderWeeklySettlement::query()
            ->where('rider_id', $settlement->rider_id)
            ->where('status', 'failed')
            ->whereDate('week_start', '<', $settlement->week_start)
            ->update([
                'status' => 'successful',
                'payout_reference' => $settlement->payout_reference,
                'processed_at' => now(),
                'remarks' => 'Carried forward and paid with the payout for '
                    . $settlement->week_start->format('d M') . ' - ' . $settlement->week_end->format('d M Y') . '.',
            ]);
    }
}
