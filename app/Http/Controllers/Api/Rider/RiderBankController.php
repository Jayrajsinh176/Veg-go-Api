<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RiderBankDetail;
use Illuminate\Support\Facades\Validator;

class RiderBankController extends Controller
{
    public function saveBankDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account_holder_name' => 'required|string|max:255',
            'bank_name'            => 'required|string|max:255',
            'account_number'       => 'required|string|max:30',
            'confirm_account_number' => 'required|same:account_number',
            'ifsc_code'            => 'required|string|max:20',
            'branch_name'          => 'nullable|string|max:255',
            // 'upi_id'               => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $rider = $request->user();

        $bankDetail = RiderBankDetail::updateOrCreate(
            [
                'rider_id' => $rider->id,
            ],
            [
                'account_holder_name' => $request->account_holder_name,
                'bank_name'           => $request->bank_name,
                'account_number'      => $request->account_number,
                'ifsc_code'           => strtoupper($request->ifsc_code),
                'branch_name'         => $request->branch_name,
                // 'upi_id'              => $request->upi_id,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Bank details saved successfully.',
            'data' => $bankDetail,
        ]);
    }

    public function getBankDetails(Request $request)
{
    $rider = $request->user();

    $bankDetail = RiderBankDetail::where(
        'rider_id',
        $rider->id
    )->first();

    if (!$bankDetail) {
        return response()->json([
            'success' => false,
            'message' => 'Bank details not found.'
        ], 404);
    }

    return response()->json([
        'success' => true,
        'data' => $bankDetail
    ]);
}

}