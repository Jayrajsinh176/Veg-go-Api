<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Restaurant;
use App\Models\RestaurantOtp;
use Carbon\Carbon;

class RestaurantAuthController extends Controller
{
    /**
     * Send OTP
     */
    public function sendOtp(Request $request)
    {
        // Validate Request
        $request->validate([
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
        ]);

      $otp = $this->generateAndSaveOtp($request->mobile);

        // TODO:
        // Send OTP using SMS Provider

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully.',
            'otp'     => $otp, // Remove this in Production
        ], 200);
    }

public function verifyOtp(Request $request)
{
    // Validate Request
    $request->validate([
        'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
        'otp'    => 'required|digits:6',
    ]);

    // Find OTP Record
    $otpRecord = RestaurantOtp::where('mobile', $request->mobile)
        ->where('otp', $request->otp)
        ->first();

    // OTP Not Found
    if (!$otpRecord) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid OTP.',
        ], 422);
    }

    // Check OTP Expiry
    if (Carbon::now()->gt($otpRecord->expires_at)) {
        return response()->json([
            'success' => false,
            'message' => 'OTP has expired.',
        ], 422);
    }

// Delete OTP after successful verification
$otpRecord->delete();

    // Check if Restaurant Already Exists
    $restaurant = Restaurant::where('mobile', $request->mobile)->first();

    // If Restaurant Does Not Exist, Create New Restaurant
    if (!$restaurant) {

        $restaurant = Restaurant::create([
            'mobile' => $request->mobile,
            'status' => 'draft',
            'onboarding_step' => 1,
            'is_profile_completed' => false,
        ]);

        $isNewRestaurant = true;

    } else {

        $isNewRestaurant = false;

    }

  // Delete Old Tokens
$restaurant->tokens()->delete();

// Generate New Sanctum Token
$token = $restaurant->createToken('restaurant-token')->plainTextToken;

    // Return Response
    return response()->json([
        'success' => true,
        'message' => 'OTP verified successfully.',
        'token' => $token,
        'is_new_restaurant' => $isNewRestaurant,
        'restaurant' => $restaurant,
        'next_screen' => $isNewRestaurant
            ? 'restaurant_information'
            : 'dashboard',
    ], 200);
}

public function resendOtp(Request $request)
{
    // Validate Request
    $request->validate([
        'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
    ]);

$otp = $this->generateAndSaveOtp($request->mobile);

    // TODO:
    // Send OTP using SMS Gateway

    return response()->json([
        'success' => true,
        'message' => 'OTP resent successfully.',
        'otp' => $otp, // Remove in Production
    ], 200);
}

private function generateAndSaveOtp($mobile)
{
    // Development OTP
    $otp = '123456';

    // Production OTP
    // $otp = rand(100000, 999999);

    // Delete Previous OTP
    RestaurantOtp::where('mobile', $mobile)->delete();

    // Save New OTP
    RestaurantOtp::create([
        'mobile' => $mobile,
        'otp' => $otp,
        'expires_at' => Carbon::now()->addMinutes(5),
    ]);

    return $otp;
}

}