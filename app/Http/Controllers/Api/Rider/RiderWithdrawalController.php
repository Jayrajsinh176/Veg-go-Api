<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\RiderWithdrawal;
use App\Models\RiderBankDetail;
use App\Models\RiderUpiDetail;

class RiderWithdrawalController extends Controller
{
    public function requestWithdrawal(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'withdraw_method' => 'required|in:bank,upi',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $rider = $request->user();

        if ($request->amount > $rider->wallet_balance) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient wallet balance.',
            ], 422);
        }

        $bankDetailId = null;
        $upiDetailId = null;

        if ($request->withdraw_method === 'bank') {

            $bank = RiderBankDetail::where('rider_id', $rider->id)->first();

            if (!$bank) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please add your bank details first.',
                ], 422);
            }

            if (!$bank->is_verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your bank account is not verified yet.',
                ], 422);
            }

            $bankDetailId = $bank->id;
        }

        if ($request->withdraw_method === 'upi') {

            $upi = RiderUpiDetail::where('rider_id', $rider->id)->first();

            if (!$upi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please add your UPI details first.',
                ], 422);
            }

            if (!$upi->is_verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your UPI ID is not verified yet.',
                ], 422);
            }

            $upiDetailId = $upi->id;
        }

        $withdrawal = RiderWithdrawal::create([
            'rider_id' => $rider->id,
            'amount' => $request->amount,
            'withdraw_method' => $request->withdraw_method,
            'bank_detail_id' => $bankDetailId,
            'upi_detail_id' => $upiDetailId,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal request submitted successfully.',
            'data' => $withdrawal,
        ]);
    }

    public function withdrawalHistory(Request $request)
{
    $rider = $request->user();

    $withdrawals = RiderWithdrawal::where('rider_id', $rider->id)
        ->latest()
        ->get()
        ->map(function ($withdrawal) {

            return [
                'id' => $withdrawal->id,
                'amount' => (float) $withdrawal->amount,
                'withdraw_method' => $withdrawal->withdraw_method,
               'status' => match ($withdrawal->status) {
    'approved' => 'successful',
    'rejected' => 'failed',
    default => 'pending',
},
                'remarks' => $withdrawal->remarks,
                'approved_at' => $withdrawal->approved_at,
                'date' => $withdrawal->created_at->format('M d, Y'),
                'created_at' => $withdrawal->created_at,
            ];
        });

    return response()->json([
        'success' => true,
        'data' => $withdrawals,
    ]);
}

}