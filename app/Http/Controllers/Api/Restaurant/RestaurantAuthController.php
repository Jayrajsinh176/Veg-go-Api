<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Restaurant;
use App\Models\RestaurantOtp;
use Carbon\Carbon;
use App\Services\Msg91OtpService;

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

    // Block deactivated restaurant accounts
if ($restaurant && $restaurant->status === 'inactive') {
    return response()->json([
        'success' => false,
        'message' => 'Your restaurant account has been deactivated.',
    ], 403);
}

    // Block restaurants suspended by admin until the suspension is lifted
if ($restaurant && $restaurant->status === 'suspended') {
    return response()->json([
        'success' => false,
        'is_suspended' => true,
        'message' => 'Your restaurant account has been suspended by admin. Please contact support.',
    ], 403);
}

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

        $isNewRestaurant = $this->notStartedOnboarding($restaurant);

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
        'onboarding_step' => (int) $restaurant->onboarding_step,
        'next_screen' => $this->nextScreen($restaurant, $isNewRestaurant),
    ], 200);
}

/**
 * Log in or register restaurant partner using MSG91 verified access token.
 */
public function msg91Login(Request $request, Msg91OtpService $msg91)
{
    $request->validate([
        'access_token' => ['required', 'string', 'max:10000'],
    ]);

    $result = $msg91->verifyAccessToken($request->access_token);

    if (!$result) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid or expired MSG91 access token.',
        ], 422);
    }

    $rawPhone = (string) ($result['message'] ?? '');
    $mobile = substr(preg_replace('/\D/', '', $rawPhone), -10);

    if (strlen($mobile) !== 10) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid mobile number received from MSG91.',
        ], 422);
    }

    $restaurant = Restaurant::where('mobile', $mobile)->first();

    // Block deactivated restaurant accounts
    if ($restaurant && $restaurant->status === 'inactive') {
        return response()->json([
            'success' => false,
            'message' => 'Your restaurant account has been deactivated.',
        ], 403);
    }

    // Block restaurants suspended by admin
    if ($restaurant && $restaurant->status === 'suspended') {
        return response()->json([
            'success' => false,
            'is_suspended' => true,
            'message' => 'Your restaurant account has been suspended by admin. Please contact support.',
        ], 403);
    }

    if (!$restaurant) {
        $restaurant = Restaurant::create([
            'mobile' => $mobile,
            'status' => 'draft',
            'onboarding_step' => 1,
            'is_profile_completed' => false,
        ]);

        $isNewRestaurant = true;
    } else {
        $isNewRestaurant = $this->notStartedOnboarding($restaurant);
    }

    // Delete Old Tokens
    $restaurant->tokens()->delete();

    // Generate New Sanctum Token
    $token = $restaurant->createToken('restaurant-token')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Login successful.',
        'token' => $token,
        'is_new_restaurant' => $isNewRestaurant,
        'restaurant' => $restaurant,
        'onboarding_step' => (int) $restaurant->onboarding_step,
        'next_screen' => $this->nextScreen($restaurant, $isNewRestaurant),
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

/**
 * An existing account counts as new until its owner saves the first
 * onboarding step (e.g. created by the super admin via Manual Entry)
 */
private function notStartedOnboarding(Restaurant $restaurant): bool
{
    return $restaurant->status === 'draft' && (int) $restaurant->onboarding_step <= 1;
}

/**
 * Where the app goes after login. A draft account (unfinished onboarding,
 * or created by the super admin) must resume onboarding: the dashboard
 * is closed until the restaurant is approved.
 */
private function nextScreen(Restaurant $restaurant, bool $isNewRestaurant): string
{
    if ($isNewRestaurant) {
        return 'restaurant_information';
    }

    if ($restaurant->status === 'draft') {
        return 'onboarding';
    }

    return 'dashboard';
}

private function generateAndSaveOtp($mobile)
{
    // Generate a random 6-digit OTP
    $otp = (string) random_int(100000, 999999);

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