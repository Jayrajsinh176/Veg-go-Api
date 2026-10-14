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
use App\Models\RestaurantOrder;
use App\Models\RiderWeeklySettlement;



class RiderOrderService
{
        protected RiderEarningService $earningService;

        protected RiderSettlementService $settlementService;

        public function __construct(RiderEarningService $earningService, RiderSettlementService $settlementService)
{
    $this->earningService = $earningService;
    $this->settlementService = $settlementService;
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

    // Rider cannot accept new orders if pending COD has reached the limit
if ((float) $rider->pending_cod >= 2500) {
    return response()->json([
        'success' => false,
        'message' => 'Your pending COD amount has reached the ₹2500 limit. Please settle your COD amount before accepting a new order.',
        'pending_cod' => (float) $rider->pending_cod,
        'cod_limit' => 2500,
    ], 400);
}

    if ($order->status === 'cancelled') {
        return $this->orderCancelledResponse();
    }

    // Order should be available
    if ($order->status !== 'available') {
        return response()->json([
            'success' => false,
            'message' => 'Order is no longer available.',
        ], 400);
    }

    // Prevent another rider from accepting the same order
    if ($order->rider_id !== null) {
        return response()->json([
            'success' => false,
            'message' => 'Order is no longer available.',
        ], 409);
    }

    /*
     * Many nearby riders are notified at once, so re-check everything
     * under row locks: only one of them can get the order. Lock order:
     * restaurant order -> delivery order -> rider (same as cancellation).
     */
    $failure = DB::transaction(function () use ($rider, $order) {

        $lockedRestaurantOrder = RestaurantOrder::where('order_number', $order->order_number)
            ->lockForUpdate()
            ->first();

        $lockedOrder = DeliveryOrder::whereKey($order->id)->lockForUpdate()->first();

        Rider::whereKey($rider->id)->lockForUpdate()->first();

        if (
            $lockedOrder?->status === 'cancelled' ||
            $lockedRestaurantOrder?->order_status === 'cancelled'
        ) {
            return 'cancelled';
        }

        if (!$lockedOrder || $lockedOrder->status !== 'available' || $lockedOrder->rider_id !== null) {
            return 'taken';
        }

        $hasActiveOrder = DeliveryOrder::where('rider_id', $rider->id)
            ->whereIn('status', ['accepted', 'arrived', 'picked_up'])
            ->exists();

        if ($hasActiveOrder) {
            return 'busy';
        }

        $order->setRawAttributes($lockedOrder->getAttributes(), true);

        /*
         * Update delivery_orders
         */
        $order->rider_id = $rider->id;
        $order->status = 'accepted';
        $order->accepted_at = now();
        $order->save();

        /*
         * Update restaurant_orders
         * using the same order number.
         */
        RestaurantOrder::where('order_number', $order->order_number)
            ->update([
                'rider_id' => $rider->id,
            ]);

        /*
         * P0 Fix: If the restaurant already marked the order as
         * ready_for_pickup (before any rider accepted), the pickup_otp
         * was saved on restaurant_orders but never copied to
         * delivery_orders. Sync it now so the rider can verify pickup.
         */
        $restaurantOrder = RestaurantOrder::where(
            'order_number',
            $order->order_number
        )->first();

        if (
            $restaurantOrder &&
            !empty($restaurantOrder->pickup_otp) &&
            empty($order->pickup_otp)
        ) {
            $order->pickup_otp = $restaurantOrder->pickup_otp;
            $order->pickup_otp_expires_at = $restaurantOrder->pickup_otp_generated_at
                ? $restaurantOrder->pickup_otp_generated_at->addMinutes(30)
                : null;
            $order->restaurant_status = $restaurantOrder->order_status;
            $order->save();
        }

        return null;
    });

    if ($failure === 'cancelled') {
        return $this->orderCancelledResponse();
    }

    if ($failure === 'taken') {
        return response()->json([
            'success' => false,
            'message' => 'Order is no longer available.',
        ], 409);
    }

    if ($failure === 'busy') {
        return response()->json([
            'success' => false,
            'message' => 'Complete your current order before accepting a new order.',
        ], 400);
    }

    // Refresh order after transaction
    $order->refresh();

    // Notify customer and restaurant that rider accepted the order
    $restaurantOrder = RestaurantOrder::where('order_number', $order->order_number)->first();
    app(\App\Services\Notification\OrderNotificationService::class)->riderAssigned($order, $restaurantOrder, $rider);

    $order->load('payment');

    return response()->json([
        'success' => true,
        'message' => 'Order accepted successfully.',
        'data' => [

            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'rider_id' => $order->rider_id,
                'accepted_at' => $order->accepted_at,
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
    'cod_amount' => optional($order->payment)->payment_method === 'COD'
        ? optional($order->payment)->cod_amount
        : null,
],

            'earning' => [
                'delivery_fee' => $order->rider_earning,
            ],
        ],
    ], 200);
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

    // Restaurant must mark order as ready
    if ($order->restaurant_status !== 'ready_for_pickup') {
        return response()->json([
            'success' => false,
            'message' => 'Order is not ready yet. Please wait.',
            'order_ready' => false,
        ], 400);
    }

    // Pickup already completed
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

    if ($order->status === 'cancelled') {
        return $this->orderCancelledResponse();
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

// Mark pickup verified
$verifiedAt = now();

/*
 * Re-check under row locks so an admin cancel that landed after the
 * checks above is not overwritten. Lock order: restaurant order ->
 * delivery order (same as cancellation).
 */
$failure = DB::transaction(function () use ($order, $verifiedAt) {

    $lockedRestaurantOrder = RestaurantOrder::where('order_number', $order->order_number)
        ->lockForUpdate()
        ->first();

    $lockedOrder = DeliveryOrder::whereKey($order->id)->lockForUpdate()->first();

    if (
        $lockedOrder?->status === 'cancelled' ||
        $lockedRestaurantOrder?->order_status === 'cancelled'
    ) {
        return 'cancelled';
    }

    if (!$lockedOrder || $lockedOrder->status !== 'arrived' || $lockedOrder->pickup_verified_at) {
        return 'verified';
    }

    $order->setRawAttributes($lockedOrder->getAttributes(), true);

    // Update delivery_orders
    $order->pickup_verified_at = $verifiedAt;
    $order->picked_up_at = $verifiedAt;
    $order->status = 'picked_up';

    // Clear OTP
    $order->pickup_otp = null;
    $order->pickup_otp_expires_at = null;

    $order->save();

    /*
     * P0 Fix: Generate Delivery OTP now, at the moment of pickup,
     * instead of waiting for the customer to open the order detail
     * screen. This prevents the rider arriving at the customer's
     * doorstep and being told "Delivery OTP is not generated yet".
     */
    $deliveryOtp = str_pad(
        random_int(1000, 9999),
        4,
        '0',
        STR_PAD_LEFT
    );

    // Sync restaurant_orders + generate delivery OTP in one update
    RestaurantOrder::where(
        'order_number',
        $order->order_number
    )->update([
        'rider_id' => $order->rider_id,
        'order_status' => 'picked_up',
        'picked_up_at' => $verifiedAt,
        'pickup_verified_at' => $verifiedAt,
        'delivery_otp' => $deliveryOtp,
        'delivery_otp_expires_at' => $verifiedAt->copy()->addMinutes(30),
    ]);

    return null;
});

if ($failure === 'cancelled') {
    return $this->orderCancelledResponse();
}

if ($failure === 'verified') {
    return response()->json([
        'success' => false,
        'message' => 'Pickup has already been verified.'
    ], 400);
}

$order->refresh();

// Notify customer and restaurant that order was picked up
app(\App\Services\Notification\OrderNotificationService::class)->orderPickedUp($order, null, $rider);

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

    // Pickup already completed / delivery already verified
    if ($order->delivery_verified_at) {
        return response()->json([
            'success' => false,
            'message' => 'Delivery already completed.'
        ], 400);
    }

    // Get corresponding restaurant order
    $restaurantOrder = RestaurantOrder::where(
        'order_number',
        $order->order_number
    )->first();

    if (!$restaurantOrder) {
        return response()->json([
            'success' => false,
            'message' => 'Restaurant order not found.'
        ], 404);
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

            // OTP is generated and stored by customer order
            'otp_required' => !empty($restaurantOrder->delivery_otp),

            'delivery_verified' => !is_null(
                $order->delivery_verified_at
            ),
        ]
    ]);
}

public function verifyDeliveryOtp(
    Rider $rider,
    DeliveryOrder $order,
    Request $request
) {
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

    // Prevent duplicate verification
    if ($order->delivery_verified_at) {
        return response()->json([
            'success' => false,
            'message' => 'Delivery has already been verified.'
        ], 400);
    }

    // Validate request
    $request->validate([
        'otp' => 'required|digits:4',
    ]);

    // Get corresponding restaurant order
    $restaurantOrder = RestaurantOrder::where(
        'order_number',
        $order->order_number
    )->first();

    if (!$restaurantOrder) {
        return response()->json([
            'success' => false,
            'message' => 'Restaurant order not found.'
        ], 404);
    }

    // Check OTP exists
    if (!$restaurantOrder->delivery_otp) {
        return response()->json([
            'success' => false,
            'message' => 'Delivery OTP is not generated yet.'
        ], 400);
    }

    // Check expiry
    if (
        $restaurantOrder->delivery_otp_expires_at &&
        now()->greaterThan(
            $restaurantOrder->delivery_otp_expires_at
        )
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Delivery OTP has expired.'
        ], 400);
    }

    // Verify OTP
    if (
        $restaurantOrder->delivery_otp !==
        $request->otp
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid Delivery OTP.'
        ], 400);
    }

    // Mark delivery OTP verified
    $verifiedAt = now();

    DB::transaction(function () use (
        $order,
        $restaurantOrder,
        $verifiedAt
    ) {

        // Delivery order
        $order->delivery_verified_at = $verifiedAt;
        $order->save();

        // Clear customer OTP
        $restaurantOrder->delivery_otp = null;
        $restaurantOrder->delivery_otp_expires_at = null;
        $restaurantOrder->save();
    });

    $order->refresh();

    return response()->json([
        'success' => true,
        'message' => 'Delivery OTP verified successfully.',
        'data' => [
            'order_number' => $order->order_number,
            'otp_verified' => true,
            'delivery_verified_at' => $order->delivery_verified_at,
        ]
    ]);
}

public function deliverOrder(Rider $rider,DeliveryOrder $order,Request $request)
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

    // Customer delivery OTP must be verified before delivery
if (!$order->delivery_verified_at) {
    return response()->json([
        'success' => false,
        'message' => 'Delivery OTP must be verified before completing the delivery.'
    ], 400);
}

// Validate payment collection method
$request->validate([
    'payment_method' => 'required|in:cash,upi',
]);

// Load payment details
$order->load('payment');

$payment = $order->payment;

if (!$payment) {
    return response()->json([
        'success' => false,
        'message' => 'Payment details not found for this order.'
    ], 400);
}

    DB::beginTransaction();

    try {

    // Handle COD payment collection
if ($payment->payment_method === 'COD') {

    $collectionMethod = $request->payment_method;

    // If customer paid cash, add COD amount to rider's pending COD
  if ($collectionMethod === 'cash') {

    $codAmount = (float) $payment->cod_amount;

    if ($codAmount <= 0) {
        throw new \Exception('Invalid COD amount.');
    }

    $currentPendingCod = (float) $rider->pending_cod;

    // Maximum pending COD allowed is ₹2500
    if (($currentPendingCod + $codAmount) > 2500) {
        throw new \Exception(
            'This COD collection would exceed your ₹2500 pending COD limit. Please settle your pending COD amount first.'
        );
    }

    $rider->increment('pending_cod', $codAmount);
}

    // Mark COD payment as paid
    $payment->payment_status = 'PAID';
    $payment->paid_at = now();
    $payment->save();
}

    $distance = $this->calculateDistance(
    $order->pickup_latitude,
    $order->pickup_longitude,
    $order->delivery_latitude,
    $order->delivery_longitude
);

$earning = $this->earningService->calculateOrderEarning($distance);

// Deliver Order
$deliveredAt = now();

$order->status = 'delivered';
$order->delivered_at = $deliveredAt;
$order->rider_earning = $earning['total_amount'];
$order->save();

// Sync restaurant order
RestaurantOrder::where(
    'order_number',
    $order->order_number
)->update([
    'rider_id' => $order->rider_id,
    'order_status' => 'delivered',
    'delivered_at' => $deliveredAt,
]);

// Order delivered: credit the restaurant's earning to its wallet
$restaurantOrder = RestaurantOrder::where('order_number', $order->order_number)->first();

if ($restaurantOrder) {
    app(\App\Services\Restaurant\RestaurantEarningService::class)->credit($restaurantOrder);
}

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

// Credit wallet + this Wednesday-to-Wednesday cycle's settlement
$this->settlementService->record(
    $rider,
    'credit',
    $earning['total_amount'],
    'Delivery Earnings',
    'Wallet credited for Order #' . $order->order_number,
    'delivery_order',
    $order->id,
    $this->settlementService->settlementFor($rider, $deliveredAt),
    isEarning: true,
);

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

        $this->settlementService->record(
            $referrer,
            'credit',
            $bonus,
            'Referral Reward',
            'Referral reward for inviting a rider who completed 10 deliveries.',
            'referral',
            $referral->id,
            isEarning: true,
        );
    }

    $referral->update([
        'reward_credited' => true,
        'rewarded_at' => now(),
    ]);
}
}
        }

        DB::commit();

        // Notify customer, restaurant and rider about successful delivery
        app(\App\Services\Notification\OrderNotificationService::class)->orderDelivered($order, $restaurantOrder, $rider);

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

public function arrivedAtRestaurant(Rider $rider, DeliveryOrder $order)
{
    if ($order->rider_id !== $rider->id) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized access.',
        ], 403);
    }

    if ($order->status !== 'accepted') {
        return response()->json([
            'success' => false,
            'message' => 'Only accepted orders can be marked as arrived.',
        ], 400);
    }

    DB::transaction(function () use ($order) {

        $order->update([
            'status' => 'arrived',
            'arrived_at' => now(),
        ]);

        // Keep rider assignment synced with restaurant order
        RestaurantOrder::where(
            'order_number',
            $order->order_number
        )->update([
            'rider_id' => $order->rider_id,
        ]);
    });

    $order->refresh();

    // Notify restaurant that rider has arrived
    app(\App\Services\Notification\OrderNotificationService::class)->riderArrived($order, null, $rider);

    return response()->json([
        'success' => true,
        'message' => 'Arrived at restaurant successfully.',
        'data' => [
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'rider_id' => $order->rider_id,
                'arrived_at' => $order->arrived_at,
            ],
        ],
    ]);
}

public function cancelOrder(Rider $rider, DeliveryOrder $order, Request $request)
{
    // Check ownership
    if ($order->rider_id !== $rider->id) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized access to this order.',
        ], 403);
    }

    // If order is already picked up, rider cannot drop it via this API
    if ($order->status === 'picked_up' || $order->status === 'delivered') {
        return response()->json([
            'success' => false,
            'message' => 'Order has already been picked up. Please contact rider support for assistance.',
        ], 422);
    }

    if (!in_array($order->status, ['accepted', 'arrived'], true)) {
        return response()->json([
            'success' => false,
            'message' => 'Only accepted or arrived orders can be unassigned.',
        ], 422);
    }

    $request->validate([
        'reason' => 'required|string|max:255',
    ]);

    $reason = $request->string('reason')->toString();

    DB::transaction(function () use ($order, $rider, $reason) {
        // Reset the delivery order back to available so other riders can accept
        $order->update([
            'rider_id'    => null,
            'status'      => 'available',
            'accepted_at' => null,
            'arrived_at'  => null,
        ]);

        // Unlink rider from restaurant order
        $restaurantOrder = RestaurantOrder::where('order_number', $order->order_number)->first();

        if ($restaurantOrder) {
            $restaurantOrder->update([
                'rider_id' => null,
            ]);

            \App\Models\OrderStatusHistory::create([
                'order_id'   => $restaurantOrder->id,
                'status'     => $restaurantOrder->order_status,
                'changed_by' => 'rider',
                'remarks'    => 'Delivery partner ' . ($rider->name ?? ('#' . $rider->id)) . ' unassigned from order. Reason: ' . $reason,
            ]);
        }
    });

    $order->refresh();

    // Notify restaurant that rider unassigned from the order
    app(\App\Services\Notification\OrderNotificationService::class)->riderUnassigned($order, null, $rider, $reason);

    return response()->json([
        'success' => true,
        'message' => 'Order unassigned successfully. The order is now available for other delivery partners.',
        'data' => [
            'order_number' => $order->order_number,
            'status'       => $order->status,
            'rider_id'     => $order->rider_id,
        ],
    ], 200);
}

private function orderCancelledResponse()
{
    return response()->json([
        'success' => false,
        'message' => 'This order has been cancelled.',
    ], 400);
}

}