<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerOtp;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerProfileController extends Controller
{
   /**
 * Get customer profile.
 */
public function show(Request $request)
{
    $customer = $request->user();

    return response()->json([
        'success' => true,
        'message' => 'Profile fetched successfully.',
        'data' => [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'date_of_birth' => $customer->date_of_birth,
                'profile_photo' => $customer->profile_photo
                    ? asset('storage/' . $customer->profile_photo)
                    : null,
            ],
        ],
    ]);
}

/**
 * Update customer profile.
 */
public function update(Request $request)
{
    $customer = $request->user();

    $validated = $request->validate([
        'name' => 'nullable|string|max:255',
        'email' => 'nullable|email|max:255|unique:customers,email,' . $customer->id,
        'date_of_birth' => 'nullable|date',
        'profile_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
    ]);

    if ($request->hasFile('profile_photo')) {

        // Delete old profile photo if it exists.
        if ($customer->profile_photo) {
            \Illuminate\Support\Facades\Storage::disk('public')
                ->delete($customer->profile_photo);
        }

        $validated['profile_photo'] = $request->file('profile_photo')
            ->store('customers/profile', 'public');
    }

    $customer->update($validated);

    return response()->json([
        'success' => true,
        'message' => 'Profile updated successfully.',
        'data' => [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'date_of_birth' => $customer->date_of_birth,
                'profile_photo' => $customer->profile_photo
                    ? asset('storage/' . $customer->profile_photo)
                    : null,
            ],
        ],
    ]);
}

    /**
     * Send OTP for changing customer's phone number.
     */
    public function sendPhoneChangeOtp(Request $request)
    {
        $customer = $request->user();

        $validated = $request->validate([
            'phone' => [
                'required',
                'digits:10',
            ],
        ]);

        $newPhone = $validated['phone'];

        // Don't allow changing to the same number.
        if ($newPhone === $customer->phone) {
            return response()->json([
                'success' => false,
                'message' => 'This is already your current phone number.',
            ], 422);
        }

        // Make sure another customer is not already using this number.
        $phoneExists = Customer::where('phone', $newPhone)
            ->where('id', '!=', $customer->id)
            ->exists();

        if ($phoneExists) {
            return response()->json([
                'success' => false,
                'message' => 'This phone number is already registered with another account.',
            ], 422);
        }

        try {
            DB::beginTransaction();

            $otp = (string) random_int(100000, 999999);

            $expiresAt = now()->addSeconds(60);

            // Remove previous unused OTPs for this number.
            CustomerOtp::where('phone', $newPhone)
                ->whereNull('verified_at')
                ->delete();

            CustomerOtp::create([
                'phone' => $newPhone,
                'otp' => $otp,
                'expires_at' => $expiresAt,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully.',
                'data' => [
                    'phone' => $newPhone,
                    'expires_in' => 60,

                    // Development only.
                    // Remove before production.
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
     * Verify OTP and change customer's phone number.
     */
    public function verifyPhoneChangeOtp(Request $request)
    {
        $customer = $request->user();

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

        $newPhone = $validated['phone'];
        $otp = $validated['otp'];

        // Make sure another customer has not taken this number.
        $phoneExists = Customer::where('phone', $newPhone)
            ->where('id', '!=', $customer->id)
            ->exists();

        if ($phoneExists) {
            return response()->json([
                'success' => false,
                'message' => 'This phone number is already registered with another account.',
            ], 422);
        }

        $otpRecord = CustomerOtp::where('phone', $newPhone)
            ->where('otp', $otp)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP.',
            ], 422);
        }

        if (Carbon::now()->gt($otpRecord->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'OTP has expired.',
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Mark OTP as verified.
            $otpRecord->update([
                'verified_at' => now(),
            ]);

            // Update authenticated customer's phone.
            $customer->update([
                'phone' => $newPhone,
                'phone_verified_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Phone number updated successfully.',
                'data' => [
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'phone' => $customer->phone,
                        'email' => $customer->email,
                    ],
                ],
            ], 200);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Unable to update phone number.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}