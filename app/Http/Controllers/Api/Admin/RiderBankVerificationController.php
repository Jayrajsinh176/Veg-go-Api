<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\RiderBankDetail;
use Illuminate\Http\Request;

class RiderBankVerificationController extends Controller
{
    public function verify(Request $request, RiderBankDetail $bankDetail)
    {
        $bankDetail->update([
            'is_verified' => true,
            'verified_at' => now(),

            // Replace with authenticated admin ID later
            'verified_by' => 1,

            'rejection_reason' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Bank details verified successfully.',
            'data' => $bankDetail->fresh(),
        ]);
    }

    public function reject(Request $request, RiderBankDetail $bankDetail)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $bankDetail->update([
            'is_verified' => false,
            'verified_at' => null,

            // Replace with authenticated admin ID later
            'verified_by' => 1,

            'rejection_reason' => $request->rejection_reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Bank details rejected.',
            'data' => $bankDetail->fresh(),
        ]);
    }
}