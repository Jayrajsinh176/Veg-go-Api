<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Models\RiderOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Rider;
use Illuminate\Support\Str;

class RiderAuthController extends Controller
{
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'regex:/^[6-9][0-9]{9}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $otp = random_int(100000, 999999);

        RiderOtp::where('phone', $request->phone)
            ->whereNull('verified_at')
            ->delete();

        RiderOtp::create([
            'phone' => $request->phone,
            'otp' => $otp,
            'expires_at' => now()->addMinutes(5),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully',

            // Temporary for development only
            'otp' => $otp,
        ]);
    }

    public function verifyOtp(Request $request)
{
    $validator = Validator::make($request->all(), [
        'phone' => ['required', 'regex:/^[6-9][0-9]{9}$/'],
        'otp' => ['required', 'digits:6'],
        'language' => ['required', 'in:en,hi,gu'],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $otpRecord = RiderOtp::where('phone', $request->phone)
        ->where('otp', $request->otp)
        ->whereNull('verified_at')
        ->latest()
        ->first();

    if (!$otpRecord) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid OTP',
        ], 400);
    }

    if ($otpRecord->expires_at->isPast()) {
        return response()->json([
            'success' => false,
            'message' => 'OTP has expired',
        ], 400);
    }

    $existingRider = Rider::where('phone', $request->phone)->first();

 $data = [
    'phone_verified_at' => now(),
    'language' => $request->language,
];

// Generate referral code only for new riders
if (!$existingRider) {

    do {
        $referralCode = 'VG' . strtoupper(Str::random(6));
    } while (
        Rider::where('referral_code', $referralCode)->exists()
    );

    $data['referral_code'] = $referralCode;
}

$rider = Rider::updateOrCreate(
    ['phone' => $request->phone],
    $data
);

$rider->refresh();

    $otpRecord->update([
        'verified_at' => now(),
    ]);

    $token = $rider->createToken('rider-app')->plainTextToken;

 return response()->json([
    'success' => true,
    'message' => 'OTP verified successfully',
    'token' => $token,
    'is_new_rider' => !$existingRider,
    'onboarding_completed' => $rider->onboarding_completed,
    'rider' => [
        'id' => $rider->id,
        'phone' => $rider->phone,
        'language' => $rider->language,
        'referral_code' => $rider->referral_code,
        'status' => $rider->status,
    ]
]);
}

   public function resendOtp(Request $request)
{
    $validator = Validator::make($request->all(), [
        'phone' => ['required', 'regex:/^[6-9][0-9]{9}$/'],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $lastOtp = RiderOtp::where('phone', $request->phone)
        ->latest()
        ->first();

    if ($lastOtp && $lastOtp->created_at->diffInSeconds(now()) < 30) {
        $remainingSeconds = 30 - (int) $lastOtp->created_at->diffInSeconds(now());

        return response()->json([
            'success' => false,
            'message' => 'Please wait before requesting another OTP',
            'retry_after' => $remainingSeconds,
        ], 429);
    }

    $otp = random_int(100000, 999999);

    RiderOtp::where('phone', $request->phone)
        ->whereNull('verified_at')
        ->delete();

    RiderOtp::create([
        'phone' => $request->phone,
        'otp' => $otp,
        'expires_at' => now()->addMinutes(5),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'OTP resent successfully',

        // Temporary for development only
        'otp' => $otp,
    ]);
}
}