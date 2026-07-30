<?php

namespace App\Services\Rider;

use App\Models\DeliveryOrder;
use App\Models\Rider;
use Illuminate\Support\Facades\DB;
use App\Models\RiderEarning;
use App\Models\RiderWalletTransaction;
use App\Models\RiderReferral;
use Illuminate\Http\Request;
use App\Services\Rider\RiderEarningService;

class RiderOrderService
{
        protected RiderEarningService $earningService;
 
        public function __construct(RiderEarningService $earningService)
{
    $this->earningService = $earningService;
}

    public function acceptOrder(Rider $rider, DeliveryOrder $order)
    {
        // Rider must be approved
        if ($rider->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not approved.',
            ], 403);
        }

        // Rider must be online
        if (!$rider->is_online) {
            return response()->json([
                'success' => false,
                'message' => 'You must be online to accept orders.',
            ], 400);
        }

        // Rider must not already have an active order
$hasActiveOrder = DeliveryOrder::where('rider_id', $rider->id)
    ->whereIn('status', [
        'accepted',
        'arrived',
        'picked_up',
    ])
    ->exists();

if ($hasActiveOrder) {
    return response()->json([
        'success' => false,
        'message' => 'Complete your current order before accepting a new order.',
    ], 400);
}

        // Order should be available
        if ($order->status !== 'available') {
            return response()->json([
                'success' => false,
                'message' => 'Order is no longer available.',
            ], 400);
        }

        $order->rider_id = $rider->id;
        $order->status = 'accepted';
        $order->accepted_at = now();
        $order->save();

        $order->load('payment');

        return response()->json([
            'success' => true,
            'message' => 'Order accepted successfully.',
            'data' => [

                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                ],

                'restaurant' => [
                    'name' => $order->restaurant_name,
                    'address' => $order->restaurant_address,
                    'phone' => $order->restaurant_phone,
                    'pickup_latitude' => $order->pickup_latitude,
                    'pickup_longitude' => $order->pickup_longitude,
                ],

                'customer' => [
                    'name' => $order->customer_name,
                    'phone' => $order->customer_phone,
                ],

                'delivery' => [
                    'address' => $order->delivery_address,
                    'latitude' => $order->delivery_latitude,
                    'longitude' => $order->delivery_longitude,
                ],

                'payment' => [
                    'method' => optional($order->payment)->payment_method,
                    'status' => optional($order->payment)->payment_status,
                    'amount' => optional($order->payment)->order_amount,
                    'cod_amount' => optional($order->payment)->cod_amount,
                ],

                'earning' => [
                    'delivery_fee' => $order->rider_earning,
                ],
            ]
        ]);
    }
    public function activeOrder(Rider $rider)
{
    $order = DeliveryOrder::with(['payment', 'items'])
        ->where('rider_id', $rider->id)
        ->whereIn('status', [
            'accepted',
            'arrived',
            'picked_up'
        ])
        ->latest()
        ->first();

    if (!$order) {
        return response()->json([
            'success' => false,
            'message' => 'No active order found.',
        ]);
    }

    return response()->json([
        'success' => true,
        'data' => $order
    ]);
}

public function orderHistory(Rider $rider)
{
    $orders = DeliveryOrder::with(['payment', 'items'])
        ->where('rider_id', $rider->id)
        ->where('status', 'delivered')
        ->latest('delivered_at')
        ->get();

    return response()->json([
        'success' => true,
        'count' => $orders->count(),
        'data' => $orders->map(function ($order) {

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,

                'restaurant' => [
                    'name' => $order->restaurant_name,
                ],

                'customer' => [
                    'name' => $order->customer_name,
                ],

                'earning' => [
                    'delivery_fee' => $order->rider_earning,
                ],

                'payment' => [
                    'method' => optional($order->payment)->payment_method,
                    'status' => optional($order->payment)->payment_status,
                ],

                'status' => $order->status,
                'delivered_at' => $order->delivered_at,
            ];
        })
    ]);
}

private function calculateDistance(
    $pickupLat,
    $pickupLng,
    $deliveryLat,
    $deliveryLng
) {
    if (
        !$pickupLat || !$pickupLng ||
        !$deliveryLat || !$deliveryLng
    ) {
        return null;
    }

    $earthRadius = 6371; // Radius of Earth in KM

    $latFrom = deg2rad($pickupLat);
    $lonFrom = deg2rad($pickupLng);

    $latTo = deg2rad($deliveryLat);
    $lonTo = deg2rad($deliveryLng);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $angle = 2 * asin(
        sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) *
            cos($latTo) *
            pow(sin($lonDelta / 2), 2)
        )
    );

    return round($earthRadius * $angle, 2);
}

public function orders(Rider $rider, Request $request)
{
    $status = $request->get('status', 'active');

    $query = DeliveryOrder::where(
        'rider_id',
        $rider->id
    );

    switch ($status) {

        case 'completed':

            $query->where('status', 'delivered');

            break;

        case 'cancelled':

            $query->where('status', 'cancelled');

            break;

        default:

            $query->whereIn('status', [
                'accepted',
                'arrived',
                'picked_up',
            ]);

            break;
    }

    $orders = $query
        ->latest()
        ->get();

$formattedOrders = $orders->map(function ($order) {

    $distance = $this->calculateDistance(
        $order->pickup_latitude,
        $order->pickup_longitude,
        $order->delivery_latitude,
        $order->delivery_longitude
    );

    return [

        'id' => $order->id,

        'order_number' => $order->order_number,

        'restaurant_name' => $order->restaurant_name,

        'customer_name' => $order->customer_name,

        'delivery_address' => $order->delivery_address,

       'distance' => number_format($distance, 1) . ' km',

        'status' => $order->status,

        'earning' => (float) $order->rider_earning,

        'created_at' => $order->created_at,
    ];
});

    return response()->json([

        'success' => true,

        'status' => $status,

        'count' => $formattedOrders->count(),

        'orders' => $formattedOrders,
    ]);
}

public function pickupVerification(Rider $rider, DeliveryOrder $order)
{
    // Check ownership
    if ($order->rider_id !== $rider->id) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized access.'
        ], 403);
    }

    // Order must be in arrived status
    if ($order->status !== 'arrived') {
        return response()->json([
            'success' => false,
            'message' => 'Pickup verification is only available after arriving at the restaurant.'
        ], 400);
    }

    if ($order->pickup_verified_at) {
    return response()->json([
        'success' => false,
        'message' => 'Pickup already completed.'
    ], 400);
}

    $order->load('items');

return response()->json([
    'success' => true,
    'data' => [

        'order_number' => $order->order_number,

        'order_status' => $order->status,

        'restaurant' => [
            'name' => $order->restaurant_name,
        ],

        'otp_required' => !empty($order->pickup_otp),

        'pickup_verified' => !is_null($order->pickup_verified_at),

        'items' => $order->items->map(function ($item) {
            return [
                'name' => $item->product_name,
                'quantity' => $item->quantity,
            ];
        }),
    ]
]);
}

public function verifyPickupOtp(Rider $rider, DeliveryOrder $order, Request $request)
{
    // Check ownership
    if ($order->rider_id !== $rider->id) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized access.'
        ], 403);
    }

    // Order must be in arrived status
    if ($order->status !== 'arrived') {
        return response()->json([
            'success' => false,
            'message' => 'Order is not ready for pickup verification.'
        ], 400);
    }

    // Validate request
    $request->validate([
        'otp' => 'required|digits:4',
    ]);

    // Check OTP exists
    if (!$order->pickup_otp) {
        return response()->json([
            'success' => false,
            'message' => 'Pickup OTP is not generated yet.'
        ], 400);
    }

    // Prevent duplicate verification
if ($order->pickup_verified_at) {
    return response()->json([
        'success' => false,
        'message' => 'Pickup has already been verified.'
    ], 400);
}

    // Check expiry
    if (
        $order->pickup_otp_expires_at &&
        now()->greaterThan($order->pickup_otp_expires_at)
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Pickup OTP has expired.'
        ], 400);
    }

    // Verify OTP
    if ($order->pickup_otp !== $request->otp) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid Pickup OTP.'
        ], 400);
    }

    // Mark verified
    $order->pickup_verified_at = now();
    $order->picked_up_at = now();
    $order->status = 'picked_up';

    // Clear OTP
    $order->pickup_otp = null;
    $order->pickup_otp_expires_at = null;

    $order->save();

$order->refresh();

return response()->json([
    'success' => true,
    'status_after_save' => $order->status,
    'pickup_verified_at' => $order->pickup_verified_at,
    'picked_up_at' => $order->picked_up_at,
]);
}

public function deliveryVerification(Rider $rider, DeliveryOrder $order)
{
    // Check ownership
    if ($order->rider_id !== $rider->id) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized access.'
        ], 403);
    }

    // Order must be picked up
    if ($order->status !== 'picked_up') {
        return response()->json([
            'success' => false,
            'message' => 'Delivery verification is only available after pickup.'
        ], 400);
    }

    if ($order->delivery_verified_at) {
    return response()->json([
        'success' => false,
        'message' => 'Delivery already completed.'
    ], 400);
}

return response()->json([
    'success' => true,
    'data' => [

        'order_number' => $order->order_number,

        'order_status' => $order->status,

        'customer' => [
            'name' => $order->customer_name,
            'phone' => $order->customer_phone,
            'address' => $order->delivery_address,
        ],

        'otp_required' => !empty($order->delivery_otp),

        'delivery_verified' => !is_null($order->delivery_verified_at),
    ]
]);
}

public function verifyDeliveryOtp(Rider $rider, DeliveryOrder $order, Request $request)
{
    // Check ownership
    if ($order->rider_id !== $rider->id) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized access.'
        ], 403);
    }

    // Order must be picked up
    if ($order->status !== 'picked_up') {
        return response()->json([
            'success' => false,
            'message' => 'Order is not ready for delivery verification.'
        ], 400);
    }

    // Validate request
    $request->validate([
        'otp' => 'required|digits:4',
    ]);

    // Check OTP exists
    if (!$order->delivery_otp) {
        return response()->json([
            'success' => false,
            'message' => 'Delivery OTP is not generated yet.'
        ], 400);
    }

    // Prevent duplicate verification
if ($order->delivery_verified_at) {
    return response()->json([
        'success' => false,
        'message' => 'Delivery has already been verified.'
    ], 400);
}

    // Check expiry
    if (
        $order->delivery_otp_expires_at &&
        now()->greaterThan($order->delivery_otp_expires_at)
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Delivery OTP has expired.'
        ], 400);
    }

    // Verify OTP
    if ($order->delivery_otp !== $request->otp) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid Delivery OTP.'
        ], 400);
    }

  $order->delivery_verified_at = now();

$order->delivery_otp = null;
$order->delivery_otp_expires_at = null;

$order->save();

return response()->json([
    'success' => true,
    'message' => 'Delivery OTP verified successfully.',
    'data' => [
        'order_number' => $order->order_number,
        'otp_verified' => true,
    ]
]);

}

public function deliverOrder(Rider $rider, DeliveryOrder $order)
{
    // Check ownership
    if ($order->rider_id !== $rider->id) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized access.'
        ], 403);
    }

    // Order must be picked up
    if ($order->status !== 'picked_up') {
        return response()->json([
            'success' => false,
            'message' => 'Only picked up orders can be delivered.'
        ], 400);
    }

    DB::beginTransaction();

    try {

    $distance = $this->calculateDistance(
    $order->pickup_latitude,
    $order->pickup_longitude,
    $order->delivery_latitude,
    $order->delivery_longitude
);

$earning = $this->earningService->calculateOrderEarning($distance);

        // Deliver Order
        $order->status = 'delivered';
        $order->delivered_at = now();
        $order->rider_earning = $earning['total_amount'];
        $order->save();

        // Check if earning already exists
        $alreadyExists = RiderEarning::where(
            'delivery_order_id',
            $order->id
        )->exists();

    if (!$alreadyExists) {

            RiderEarning::create([
    'rider_id' => $rider->id,
    'delivery_order_id' => $order->id,
    'type' => 'delivery',
'amount' => $earning['total_amount'],
    'title' => 'Delivery Earnings',
    'description' => 'Earnings for Order #' . $order->order_number,
]);

// Update wallet
$rider->increment(
    'wallet_balance',
    $earning['total_amount']
);

// Refresh rider to get updated balance
$rider->refresh();

// Save wallet transaction
RiderWalletTransaction::create([
    'rider_id' => $rider->id,
    'type' => 'credit',
    'amount' => $earning['total_amount'],
    'balance_after' => $rider->wallet_balance,
    'title' => 'Delivery Earnings',
    'description' => 'Wallet credited for Order #' . $order->order_number,
    'reference_type' => 'delivery_order',
    'reference_id' => $order->id,
]);

// Check if this rider joined using a referral code
$referral = RiderReferral::where('referred_rider_id', $rider->id)
    ->where('reward_credited', false)
    ->first();

if ($referral) {

    $referral->increment('completed_orders');

$referral->refresh();

// Reward after 10 completed deliveries
if ($referral->completed_orders >= 10) {

    $bonus = 200;

    $referrer = Rider::find($referral->referrer_rider_id);

    if ($referrer) {

        $referrer->increment('wallet_balance', $bonus);

        $referrer->refresh();

        RiderWalletTransaction::create([
            'rider_id' => $referrer->id,
            'type' => 'credit',
            'amount' => $bonus,
            'balance_after' => $referrer->wallet_balance,
            'title' => 'Referral Reward',
            'description' => 'Referral reward for inviting a rider who completed 10 deliveries.',
            'reference_type' => 'referral',
            'reference_id' => $referral->id,
        ]);
    }

    $referral->update([
        'reward_credited' => true,
        'rewarded_at' => now(),
    ]);
}
}
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Order delivered successfully.',
            'data' => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'delivered_at' => $order->delivered_at,
               'earning_added' => $earning,
                'wallet_balance' => (float) $rider->fresh()->wallet_balance,
            ]
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}

}