<?php

namespace App\Services\Rider;

use App\Models\RiderDailySettlement;
use App\Models\RiderWalletTransaction;
use App\Models\RiderUpiDetail;
use App\Models\RiderBankDetail;
use Illuminate\Support\Facades\DB;

class RiderAutoPayoutService
{
    public function processDueSettlements(): int
    {

$settlements = RiderDailySettlement::where('status', 'pending')
    ->whereDate('payout_date', '<=', today())
    ->orderBy('id')
    ->get();

        $processedCount = 0;

    foreach ($settlements as $settlement) {

    DB::transaction(function () use (
        $settlement,
        &$processedCount
    ) {

        $settlement = RiderDailySettlement::where(
            'id',
            $settlement->id
        )
        ->lockForUpdate()
        ->first();

        if (!$settlement || $settlement->status !== 'pending') {
            return;
        }

        $settlement->update([
            'status' => 'processing',
        ]);

                $rider = $settlement->rider;

                if (!$rider) {
                    $settlement->update([
                        'status' => 'failed',
                        'remarks' => 'Rider not found.',
                    ]);

                    return;
                }

                if ($settlement->total_amount <= 0) {
                    $settlement->update([
                        'status' => 'failed',
                        'remarks' => 'Settlement amount must be greater than zero.',
                    ]);

                    return;
                }

                if ($rider->wallet_balance < $settlement->total_amount) {
                    $settlement->update([
                        'status' => 'failed',
                        'remarks' => 'Insufficient wallet balance.',
                    ]);

                    return;
                }

                $upi = RiderUpiDetail::where('rider_id', $rider->id)
    ->where('is_verified', true)
    ->first();

$bank = RiderBankDetail::where('rider_id', $rider->id)
    ->where('is_verified', true)
    ->first();

if (!$upi && !$bank) {
    $settlement->update([
        'status' => 'failed',
        'remarks' => 'No verified UPI or bank account found.',
    ]);

    return;
}

$payoutMethod = $upi ? 'upi' : 'bank';
                /*
                 * REAL BANK/UPI PAYMENT WILL BE CONNECTED LATER.
                 *
                 * For now, we only simulate a successful payout.
                 */

                $rider->decrement(
                    'wallet_balance',
                    $settlement->total_amount
                );

                $rider->refresh();

                RiderWalletTransaction::create([
                    'rider_id' => $rider->id,
                    'type' => 'debit',
                    'amount' => $settlement->total_amount,
                    'balance_after' => $rider->wallet_balance,
                    'title' => 'Automatic Payout',
                    'description' => 'Automatic payout for settlement date ' .
                        $settlement->settlement_date->format('Y-m-d'),
                    'reference_type' => 'daily_settlement',
                    'reference_id' => $settlement->id,
                ]);

                $settlement->update([
                    'status' => 'successful',
                   'payout_reference' => 'TEST-' . strtoupper($payoutMethod) . '-' . $settlement->id . '-' . time(),
                    'processed_at' => now(),
                    'remarks' => 'Test automatic payout processed successfully via ' . strtoupper($payoutMethod) . '.',
                ]);

                $processedCount++;
            });
        }

        return $processedCount;
    }
}