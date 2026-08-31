<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DeliveryOrder;
use App\Services\Rider\RiderOrderService;
use App\Models\RiderEarning;
use Illuminate\Support\Facades\DB;
use App\Models\RiderWalletTransaction;
use App\Models\RiderReferral;


class RiderOrderController extends Controller
{
    protected $orderService;

    public function __construct(RiderOrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function activeOrder(Request $request)
{
    return $this->orderService->activeOrder($request->user());
}

public function orderHistory(Request $request)
{
    return $this->orderService->orderHistory(
        $request->user()
    );
}

public function orders(Request $request)
{
    return $this->orderService->orders(
        $request->user(),
        $request
    );
}


    public function acceptOrder(Request $request, DeliveryOrder $order)
    {
        return $this->orderService->acceptOrder(
            $request->user(),
            $order
        );
    }

public function orderDetails(Request $request, DeliveryOrder $order)
{
    $rider = $request->user();
        $order->load(['payment', 'items']);

    // Ensure the order belongs to the authenticated rider
    if ($order->rider_id !== $rider->id) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized access to this order.'
        ], 403);
    }

   return response()->json([
    'success' => true,
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

        'items' => $order->items->map(function ($item) {
            return [
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'special_instruction' => $item->special_instruction,
            ];
        }),

        'earning' => [
            'delivery_fee' => $order->rider_earning,
        ],
    ]
]);
}

public function arrivedAtRestaurant(Request $request, DeliveryOrder $order)
{
    return $this->orderService->arrivedAtRestaurant(
        $request->user(),
        $order
    );
}

public function pickupVerification(Request $request, DeliveryOrder $order)
{
    return $this->orderService->pickupVerification(
        $request->user(),
        $order
    );
}

public function verifyPickupOtp(Request $request, DeliveryOrder $order)
{
    return $this->orderService->verifyPickupOtp(
        $request->user(),
        $order,
        $request
    );
}

// public function pickedUpOrder(Request $request, DeliveryOrder $order)
// {
//     $rider = $request->user();

//     // Check if the order belongs to this rider
//     if ($order->rider_id !== $rider->id) {
//         return response()->json([
//             'success' => false,
//             'message' => 'Unauthorized access.'
//         ], 403);
//     }

//     // Order must be in arrived state
//     if ($order->status !== 'arrived') {
//         return response()->json([
//             'success' => false,
//             'message' => 'Only arrived orders can be picked up.'
//         ], 400);
//     }

//     $order->status = 'picked_up';
//     $order->picked_up_at = now();
//     $order->save();

//     return response()->json([
//         'success' => true,
//         'message' => 'Order picked up successfully.',
//         'order' => [
//             'id' => $order->id,
//             'order_number' => $order->order_number,
//             'status' => $order->status,
//             'picked_up_at' => $order->picked_up_at,
//         ]
//     ]);
// }

public function deliveryVerification(Request $request, DeliveryOrder $order)
{
    return $this->orderService->deliveryVerification(
        $request->user(),
        $order
    );
}

public function verifyDeliveryOtp(Request $request, DeliveryOrder $order)
{
    return $this->orderService->verifyDeliveryOtp(
        $request->user(),
        $order,
        $request
    );
}

public function deliverOrder(Request $request, DeliveryOrder $order)
{
    return $this->orderService->deliverOrder(
        $request->user(),
        $order
    );
}


}
