<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DeliveryOrder;
use App\Models\RiderNotification;
use App\Models\RiderReferral;
use App\Models\Rider;
use Illuminate\Support\Facades\Validator;

class RiderProfileController extends Controller
{
    public function profile(Request $request)
    {
        $rider = $request->user()->load([
            'vehicleType',
            'city',
            'workZone',
            'kyc',
        ]);

        // Count completed deliveries
        $completedOrders = DeliveryOrder::where('rider_id', $rider->id)
            ->where('status', 'delivered')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $rider->id,
                'name' => $rider->name,
                'first_name' => $rider->first_name,
                'last_name' => $rider->last_name,
                'phone' => $rider->phone,
                'gender' => $rider->gender,
                'language' => $rider->language,

                'profile_image' => $rider->kyc && $rider->kyc->selfie
                    ? asset('storage/' . $rider->kyc->selfie)
                    : null,

                'city' => $rider->city?->name,

                'work_zone' => $rider->workZone?->name,

                'vehicle_type' => $rider->vehicleType?->name,

                'wallet_balance' => (float) $rider->wallet_balance,

                'is_online' => (bool) $rider->is_online,

                'status' => $rider->status,

                'completed_orders' => $completedOrders,

                // Rating feature is not implemented yet
                'rating' => 0.0,
            ]
        ]);
    }

    public function documents(Request $request)
{
    $rider = $request->user()->load('kyc');

    $kyc = $rider->kyc;

    if (!$kyc) {
        return response()->json([
            'success' => false,
            'message' => 'KYC details not found.'
        ], 404);
    }

    return response()->json([
        'success' => true,
        'data' => [
            'aadhaar' => [
                'number' => $kyc->aadhaar_last_four,
                'verified' => (bool) $kyc->aadhaar_verified,
                'document' => $kyc->aadhaar_document
                    ? asset('storage/' . $kyc->aadhaar_document)
                    : null,
            ],

            'pan' => [
                'has_pan' => (bool) $kyc->has_pan,
                'verified' => (bool) $kyc->pan_verified,
                'document' => $kyc->pan_document
                    ? asset('storage/' . $kyc->pan_document)
                    : null,
            ],

            'driving_license' => [
                'verified' => (bool) $kyc->driving_license_verified,
                'document' => $kyc->driving_license_document
                    ? asset('storage/' . $kyc->driving_license_document)
                    : null,
            ],

            'puc' => [
                'verified' => (bool) $kyc->puc_verified,
                'document' => $kyc->puc_document
                    ? asset('storage/' . $kyc->puc_document)
                    : null,
            ],

            'rc_book' => [
                'verified' => (bool) $kyc->rc_book_verified,
                'document' => $kyc->rc_book_document
                    ? asset('storage/' . $kyc->rc_book_document)
                    : null,
            ],

            'insurance' => [
                'verified' => (bool) $kyc->insurance_verified,
                'document' => $kyc->insurance_document
                    ? asset('storage/' . $kyc->insurance_document)
                    : null,
            ],

            'selfie' => $kyc->selfie
                ? asset('storage/' . $kyc->selfie)
                : null,

            'status' => $kyc->status,
            'rejection_reason' => $kyc->rejection_reason,
        ]
    ]);
}

public function notifications(Request $request)
{
    $notifications = RiderNotification::where('rider_id', $request->user()->id)
        ->latest()
        ->get()
        ->map(function ($notification) {

            return [
                'id' => $notification->id,
                'title' => $notification->title,
                'message' => $notification->message,
                'type' => $notification->type,
                'is_read' => $notification->is_read,
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at,
            ];
        });

    return response()->json([
        'success' => true,
        'data' => $notifications,
    ]);
}

public function markNotificationRead(Request $request, $id)
{
    $notification = RiderNotification::where('id', $id)
        ->where('rider_id', $request->user()->id)
        ->first();

    if (!$notification) {
        return response()->json([
            'success' => false,
            'message' => 'Notification not found.',
        ], 404);
    }

    $notification->update([
        'is_read' => true,
        'read_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Notification marked as read.',
    ]);
}

public function markAllNotificationsRead(Request $request)
{
    RiderNotification::where('rider_id', $request->user()->id)
        ->where('is_read', false)
        ->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

    return response()->json([
        'success' => true,
        'message' => 'All notifications marked as read.',
    ]);
}

public function applyReferral(Request $request)
{
    $validator = Validator::make($request->all(), [
        'referral_code' => 'required|string',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    // Rider has already used a referral code
    if ($rider->referredBy()->exists()) {
        return response()->json([
            'success' => false,
            'message' => 'Referral code has already been applied.',
        ], 400);
    }

    // Find referrer
    $referrer = Rider::where('referral_code', $request->referral_code)->first();

    if (!$referrer) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid referral code.',
        ], 404);
    }

    // Cannot use own referral code
    if ($referrer->id == $rider->id) {
        return response()->json([
            'success' => false,
            'message' => 'You cannot use your own referral code.',
        ], 400);
    }

    RiderReferral::create([
        'referrer_rider_id' => $referrer->id,
        'referred_rider_id' => $rider->id,
        'referral_code' => $referrer->referral_code,
        'completed_orders' => 0,
        'reward_credited' => false,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Referral code applied successfully.',
    ]);
}

public function referEarn(Request $request)
{
    $rider = $request->user();

    $rewardPerReferral = 200;

    $friendsJoined = RiderReferral::where('referrer_rider_id', $rider->id)
        ->count();

    $totalRewards = RiderReferral::where('referrer_rider_id', $rider->id)
        ->where('reward_credited', true)
        ->count() * $rewardPerReferral;

    return response()->json([
        'success' => true,
        'data' => [
            'reward_per_referral' => $rewardPerReferral,
            'required_orders' => 10,
            'referral_code' => $rider->referral_code,
            'friends_joined' => $friendsJoined,
            'total_rewards' => $totalRewards,
        ]
    ]);
}


public function logout(Request $request)
{
    $request->user()->currentAccessToken()->delete();

    return response()->json([
        'success' => true,
        'message' => 'Logged out successfully.',
    ]);
}

}