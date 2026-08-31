<?php

namespace App\Services\Restaurant;

use App\Models\OrderStatusHistory;
use App\Models\RestaurantOrder;
use App\Models\DeliveryOrder;
use App\Models\RestaurantWalletTransaction;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;

class RestaurantOrderService
{
    /**
     * Restaurant Order List
     */
public function index(Request $request)
{
    $restaurant = $request->user();

    $query = RestaurantOrder::with([
        'items',
        'payment',
    ])->where('restaurant_id', $restaurant->id);

    if ($request->filled('status')) {
        $query->where('order_status', $request->status);
    }

    $orders = $query->latest()->get();

    return response()->json([
        'success' => true,
        'message' => 'Orders fetched successfully.',
        'data' => $orders,
    ]);
}

    public function pending(Request $request)
{
    $restaurant = $request->user();

    $orders = RestaurantOrder::with([
        'items',
        'payment',
    ])
    ->where('restaurant_id', $restaurant->id)
    ->where('order_status', 'pending')
  ->orderBy('placed_at', 'asc')
    ->get();

    return response()->json([
        'success' => true,
        'message' => 'Pending orders fetched successfully.',
        'data' => $orders,
    ]);
}

public function preparing(Request $request)
{
    $restaurant = $request->user();

    $orders = RestaurantOrder::with([
        'items',
        'payment',
    ])
    ->where('restaurant_id', $restaurant->id)
    ->whereIn('order_status', [
        'accepted',
        'preparing',
    ])
->orderBy('accepted_at', 'asc')
    ->get();

    return response()->json([
        'success' => true,
        'message' => 'Preparing orders fetched successfully.',
        'data' => $orders,
    ]);
}

    public function show(Request $request, $orderId)
{
    $restaurant = $request->user();

$order = RestaurantOrder::with([
    'items',
    'payment',
    'statusHistories',
])
->where('restaurant_id', $restaurant->id)
->findOrFail($orderId);

    return response()->json([
        'success' => true,
        'message' => 'Order details fetched successfully.',
        'data' => $order,
    ]);
}

public function accept(Request $request, $orderId)
{
    $restaurant = $request->user();

    $order = RestaurantOrder::where('restaurant_id', $restaurant->id)
        ->with([
            'restaurant.address',
            'customer',
            'deliveryAddress',
            'items',
        ])
        ->findOrFail($orderId);

    if ($order->order_status !== 'pending') {

        return response()->json([
            'success' => false,
            'message' => 'Only pending orders can be accepted.',
        ], 422);

    }

    /*
     * Check restaurant address
     */
    if (!$order->restaurant || !$order->restaurant->address) {

        return response()->json([
            'success' => false,
            'message' => 'Restaurant address not found.',
        ], 422);

    }

    /*
     * Check customer delivery address
     */
    if (!$order->deliveryAddress) {

        return response()->json([
            'success' => false,
            'message' => 'Delivery address not found.',
        ], 422);

    }

    $restaurantAddress = $order->restaurant->address;
    $deliveryAddress = $order->deliveryAddress;

    /*
     * Check coordinates because delivery_orders
     * requires all four coordinates.
     */
    if (
        $restaurantAddress->latitude === null ||
        $restaurantAddress->longitude === null ||
        $deliveryAddress->latitude === null ||
        $deliveryAddress->longitude === null
    ) {

        return response()->json([
            'success' => false,
            'message' => 'Pickup or delivery location is not available.',
        ], 422);

    }

    DB::transaction(function () use (
        $order,
        $restaurantAddress,
        $deliveryAddress
    ) {

        /*
         * Update restaurant order
         */
        $order->update([
            'order_status' => 'accepted',
            'accepted_at' => now(),
        ]);

        /*
         * Add restaurant status history
         */
        OrderStatusHistory::create([
            'order_id'   => $order->id,
            'status'     => 'accepted',
            'changed_by' => 'restaurant',
            'remarks'    => 'Order accepted by restaurant',
        ]);

        /*
         * Create Rider delivery order.
         *
         * firstOrCreate prevents duplicate delivery orders
         * if the same order is processed again.
         */
       $deliveryOrder = DeliveryOrder::firstOrCreate(
    [
        'order_number' => $order->order_number,
    ],
    [
        'restaurant_name' => $order->restaurant->restaurant_name,

        'restaurant_address' =>
            $restaurantAddress->complete_address
            ?: $restaurantAddress->full_address,

        'restaurant_phone' => $order->restaurant->mobile,

        'customer_name' => optional($order->customer)->name,

        'customer_phone' => optional($order->customer)->phone,

        'delivery_address' => $deliveryAddress->address,

        'pickup_latitude' => $restaurantAddress->latitude,

        'pickup_longitude' => $restaurantAddress->longitude,

        'delivery_latitude' => $deliveryAddress->latitude,

        'delivery_longitude' => $deliveryAddress->longitude,

        'rider_earning' => $order->delivery_charge ?? 0,

        'rider_id' => null,

        'status' => 'available',
    ]
);

/*
|--------------------------------------------------------------------------
| Copy restaurant order items to rider delivery order
|--------------------------------------------------------------------------
*/

foreach ($order->items as $item) {

    \App\Models\OrderItem::create([
        'delivery_order_id' => $deliveryOrder->id,
        'product_name' => $item->menu_item_name,
        'quantity' => $item->quantity,
        'price' => $item->unit_price,
        'special_instruction' => $item->special_instruction,
    ]);
}
    });

    /*
     * Load the same relationships as before.
     */
    $order->load([
        'items',
        'payment',
        'statusHistories',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Order accepted successfully.',
        'data' => $order,
    ]);
}

public function updateStatus(Request $request, $orderId)
{
    $request->validate([
        'status' => 'required|in:preparing,ready_for_pickup',
    ]);

    $restaurant = $request->user();

    $order = RestaurantOrder::where('restaurant_id', $restaurant->id)
        ->findOrFail($orderId);

    $allowedTransitions = [
        'accepted' => 'preparing',
        'preparing' => 'ready_for_pickup',
    ];

    if (
        !isset($allowedTransitions[$order->order_status]) ||
        $allowedTransitions[$order->order_status] !== $request->status
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid order status transition.',
        ], 422);
    }

    DB::transaction(function () use ($order, $request, $restaurant) {

        $updateData = [
            'order_status' => $request->status,
        ];

        // Sync restaurant status to delivery order
DeliveryOrder::where('order_number', $order->order_number)
    ->update([
        'restaurant_status' => $request->status,
    ]);

        /*
        |--------------------------------------------------------------------------
        | Preparing
        |--------------------------------------------------------------------------
        */
        if ($request->status === 'preparing') {

            $updateData['preparing_at'] = now();
        }

        /*
        |--------------------------------------------------------------------------
        | Ready For Pickup
        |--------------------------------------------------------------------------
        */
        if ($request->status === 'ready_for_pickup') {

            // Generate pickup OTP
            $updateData['ready_for_pickup_at'] = now();

            $updateData['pickup_otp'] = str_pad(
                random_int(1000, 9999),
                4,
                '0',
                STR_PAD_LEFT
            );

            $updateData['pickup_otp_generated_at'] = now();

            if ($order->rider_id) {
    DeliveryOrder::where('order_number', $order->order_number)
        ->where('rider_id', $order->rider_id)
        ->update([
            'pickup_otp' => $updateData['pickup_otp'],
        ]);
}
            /*
            |--------------------------------------------------------------------------
            | Restaurant Commission & Earning
            |--------------------------------------------------------------------------
            */

            $commissionPercentage = (float) config(
                'restaurant.commission_percentage',
                20.00
            );

            // Commission is calculated on restaurant food subtotal
            $commissionAmount = round(
                ($order->subtotal * $commissionPercentage) / 100,
                2
            );

            // Restaurant receives remaining amount
            $restaurantEarning = round(
                $order->subtotal - $commissionAmount,
                2
            );

            /*
            |--------------------------------------------------------------------------
            | Update Order Earning
            |--------------------------------------------------------------------------
            */

            $updateData['commission_percentage'] = $commissionPercentage;
            $updateData['commission_amount'] = $commissionAmount;
            $updateData['restaurant_earning'] = $restaurantEarning;
            $updateData['earning_status'] = 'credited';
            $updateData['earning_credited_at'] = now();

            /*
            |--------------------------------------------------------------------------
            | Add Earning To Restaurant Wallet
            |--------------------------------------------------------------------------
            */

            $newBalance = round(
                (float) $restaurant->wallet_balance + $restaurantEarning,
                2
            );

            $restaurant->update([
                'wallet_balance' => $newBalance,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Wallet Transaction
            |--------------------------------------------------------------------------
            */

            RestaurantWalletTransaction::create([
                'restaurant_id' => $restaurant->id,
                'type' => 'credit',
                'amount' => $restaurantEarning,
                'balance_after' => $newBalance,
                'title' => 'Order Payment',
                'description' => 'Restaurant earning credited for order ' . $order->order_number,
                'reference_type' => 'restaurant_order',
                'reference_id' => $order->id,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Update Order
        |--------------------------------------------------------------------------
        */

        $order->update($updateData);

        /*
        |--------------------------------------------------------------------------
        | Order Status History
        |--------------------------------------------------------------------------
        */

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status' => $request->status,
            'changed_by' => 'restaurant',
            'remarks' => 'Order marked as ' . str_replace(
                '_',
                ' ',
                $request->status
            ),
        ]);
    });

    $order->refresh()->load([
        'items',
        'payment',
        'statusHistories',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Order status updated successfully.',
        'data' => $order,
    ]);
}

public function reject(Request $request, $orderId)
{
    $request->validate([
        'reason' => 'required|string|max:255',
    ]);

    $restaurant = $request->user();

    $order = RestaurantOrder::where('restaurant_id', $restaurant->id)
        ->findOrFail($orderId);

    // Reject only if order is still pending
    if ($order->order_status !== 'pending') {
        return response()->json([
            'success' => false,
            'message' => 'Only pending orders can be rejected.',
        ], 422);
    }

    DB::transaction(function () use ($order, $request) {

        $order->update([
            'order_status'  => 'cancelled',
            'cancel_reason' => $request->reason,
            'cancelled_at'  => now(),
        ]);

        OrderStatusHistory::create([
            'order_id'   => $order->id,
            'status'     => 'cancelled',
            'changed_by' => 'restaurant',
            'remarks'    => $request->reason,
        ]);
    });

    $order->refresh()->load([
        'items',
        'payment',
        'statusHistories',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Order rejected successfully.',
        'data' => $order,
    ]);
}

}