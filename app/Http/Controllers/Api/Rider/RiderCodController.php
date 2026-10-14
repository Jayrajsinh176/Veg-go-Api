<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider;
use App\Models\RiderCodSettlement;
use App\Services\Rider\RiderSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RiderCodController extends Controller
{
    public function settle(Request $request, RiderSettlementService $settlementService)
    {
        $riderId = $request->user()->id;

        DB::beginTransaction();

        try {

            // Lock rider data
            $rider = Rider::lockForUpdate()->findOrFail($riderId);

            $pendingCod = (float) $rider->pending_cod;
            $walletBalance = (float) $rider->wallet_balance;

            // No COD pending
            if ($pendingCod <= 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'You have no pending COD amount to settle.',
                ], 400);
            }

            // Calculate how much COD can be settled
            $settlementAmount = min($walletBalance, $pendingCod);

            if ($settlementAmount <= 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient wallet balance to settle COD. Please contact admin to settle it in cash.',
                    'pending_cod' => $pendingCod,
                    'wallet_balance' => $walletBalance,
                ], 400);
            }

            // Debit wallet, reduce pending COD and write history
            $settlementService->settleCod($rider, $settlementAmount, 'wallet', 'rider');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'COD amount settled successfully.',
                'data' => [
                    'settled_amount' => $settlementAmount,
                    'remaining_cod' => (float) $rider->pending_cod,
                    'pending_cod' => (float) $rider->pending_cod,
                    'wallet_balance' => (float) $rider->wallet_balance,
                ],
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Every COD settlement: from the app, deducted at weekly payout, or paid in cash to admin
     */
    public function settlementHistory(Request $request)
    {
        $rider = $request->user();

        $settlements = RiderCodSettlement::where('rider_id', $rider->id)
            ->with('walletTransaction:id,balance_after')
            ->latest()
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'amount' => $row->amount,
                'method' => $row->method,
                'source' => $row->source,
                'title' => match ($row->source) {
                    'auto_payout' => 'COD Adjusted in Weekly Payout',
                    'admin' => 'COD Paid to Admin',
                    default => 'COD Settlement',
                },
                'pending_cod_after' => $row->pending_cod_after,
                'balance_after' => $row->walletTransaction?->balance_after,
                'settlement_id' => $row->settlement_id,
                'remarks' => $row->remarks,
                'created_at' => $row->created_at,
            ]);

        return response()->json([
            'success' => true,
            'pending_cod' => (float) $rider->pending_cod,
            'data' => $settlements,
        ]);
    }
}
