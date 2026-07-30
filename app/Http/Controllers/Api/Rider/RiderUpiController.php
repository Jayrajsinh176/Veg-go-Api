<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\RiderUpiDetail;

class RiderUpiController extends Controller
{
    public function saveUpiDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'holder_name' => 'required|string|max:255',
            'upi_id' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z]{2,64}$/'
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $rider = $request->user();

        $upiDetail = RiderUpiDetail::updateOrCreate(
            [
                'rider_id' => $rider->id,
            ],
            [
                'holder_name' => $request->holder_name,
                'upi_id' => strtolower($request->upi_id),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'UPI details saved successfully.',
            'data' => $upiDetail,
        ]);
    }

    public function getUpiDetails(Request $request)
    {
        $upiDetail = $request->user()->upiDetail;

        return response()->json([
            'success' => true,
            'data' => $upiDetail,
        ]);
    }
}