<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CustomerAuthController extends Controller
{
    /**
     * Send OTP to customer mobile number.
     */
    public function sendOtp(Request $request)
    {
        $validated = $request->validate([
            'phone' => [
                'required',
                'digits:10',
            ],
        ]);

        $phone = $validated['phone'];

        try {
            DB::beginTransaction();

            // Find existing customer or create a new one
            $customer = Customer::firstOrCreate(
                [
                    'phone' => $phone,
                ],
                [
                    'name' => null,
                    'email' => null,
                ]
            );

            // Generate 6 digit OTP
            $otp = (string) random_int(100000, 999999);

            // OTP valid for 60 seconds
            $expiresAt = now()->addSeconds(60);

            // Delete previous unused OTPs
            CustomerOtp::where('phone', $phone)
                ->whereNull('verified_at')
                ->delete();

            // Save new OTP
            CustomerOtp::create([
                'phone' => $phone,
                'otp' => $otp,
                'expires_at' => $expiresAt,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully.',
                'data' => [
                    'phone' => $phone,
                    'expires_in' => 60,

                    // Development only
                    // Remove before production
                    'otp' => $otp,
                ],
            ], 200);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Unable to send OTP.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify customer OTP.
     */
    public function verifyOtp(Request $request)
    {
        // Validate request
        $validated = $request->validate([
            'phone' => [
                'required',
                'digits:10',
            ],
            'otp' => [
                'required',
                'digits:6',
            ],
        ]);

        $phone = $validated['phone'];
        $otp = $validated['otp'];

        // Find the latest OTP for this phone
        $otpRecord = CustomerOtp::where('phone', $phone)
            ->where('otp', $otp)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        // OTP not found
        if (!$otpRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP.',
            ], 422);
        }

        // Check OTP expiry
        if (Carbon::now()->gt($otpRecord->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'OTP has expired.',
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Mark OTP as verified
            $otpRecord->update([
                'verified_at' => now(),
            ]);

            // Find customer
            $customer = Customer::where('phone', $phone)->first();

            // Customer should exist because sendOtp creates it
            if (!$customer) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Customer account not found.',
                ], 404);
            }

            // Mark phone as verified
            $customer->update([
                'phone_verified_at' => now(),
            ]);

            // Check whether this is the customer's first login
            $isNewCustomer = $customer->wasRecentlyCreated;

            // Remove previous tokens
            $customer->tokens()->delete();

            // Create new Sanctum token
            $token = $customer
                ->createToken('customer-token')
                ->plainTextToken;

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'OTP verified successfully.',
                'token' => $token,
                'is_new_customer' => $isNewCustomer,
                'customer' => $customer,
            ], 200);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Unable to verify OTP.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

/**
 * Resend OTP to customer mobile number.
 */
public function resendOtp(Request $request)
{
    $validated = $request->validate([
        'phone' => [
            'required',
            'digits:10',
        ],
    ]);

    $phone = $validated['phone'];

    try {
        // Get the latest OTP record
        $latestOtp = CustomerOtp::where('phone', $phone)
            ->latest('created_at')
            ->first();

        /*
         * Prevent resend for 13 seconds after
         * the previous OTP was created.
         */
        if ($latestOtp) {

            $allowedAt = $latestOtp->created_at->copy()->addSeconds(13);

            if (now()->lt($allowedAt)) {

                $remainingSeconds = max(
                    1,
                    (int) ceil(now()->diffInSeconds($allowedAt, false))
                );

                return response()->json([
                    'success' => false,
                    'message' => 'Please wait before requesting another OTP.',
                    'data' => [
                        'retry_after' => $remainingSeconds,
                    ],
                ], 429);
            }
        }

        // Generate new OTP
        $otp = (string) random_int(100000, 999999);

        // OTP valid for 60 seconds
        $expiresAt = now()->addSeconds(60);

        // Remove previous OTPs
        CustomerOtp::where('phone', $phone)->delete();

        // Create new OTP
        CustomerOtp::create([
            'phone' => $phone,
            'otp' => $otp,
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'OTP resent successfully.',
            'data' => [
                'phone' => $phone,
                'expires_in' => 60,

                // Development only
                // Remove before production
                'otp' => $otp,
            ],
        ], 200);

    } catch (\Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => 'Unable to resend OTP.',
            'error' => $e->getMessage(),
        ], 500);
    }
}

/**
 * Logout customer.
 */
public function logout(Request $request)
{
    $customer = $request->user();

    // Delete the current access token.
    $customer->currentAccessToken()?->delete();

    return response()->json([
        'success' => true,
        'message' => 'Logged out successfully.',
    ], 200);
}

}