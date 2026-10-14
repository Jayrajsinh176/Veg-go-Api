<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantOrder;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    /**
     * Get all orders
     */
    public function index(Request $request)
    {
        $orders = RestaurantOrder::query()
            ->select([
                'id',
                'restaurant_id',
                'customer_id',
                'delivery_address_id',
                'rider_id',
                'order_number',
                'subtotal',
                'delivery_charge',
                'packing_charge',
                'tax',
                'discount',
                'total_amount',
                'payment_method',
                'payment_status',
                'order_status',
                'placed_at',
                'accepted_at',
                'preparing_at',
                'ready_for_pickup_at',
                'picked_up_at',
                'delivered_at',
                'cancelled_at',
                'cancel_reason',
                'notes',
            ])
            ->with([
                'restaurant:id,restaurant_name',
                'customer:id,name,phone,email',
                'rider:id,name,phone',
                'deliveryAddress:id,customer_id,label,receiver_name,receiver_phone,address,landmark,pincode,latitude,longitude',
                'items:id,order_id,menu_item_id,menu_item_name,variant_name,quantity,unit_price,total_price,special_instruction',
                'payment:id,order_id,payment_method,payment_status,order_amount,delivery_fee,packing_charge,tax,discount,total_amount,transaction_id,payment_gateway,paid_at',
            ])
            ->when(
                $request->filled('restaurant_id'),
                fn ($query) => $query->where(
                    'restaurant_id',
                    $request->integer('restaurant_id')
                )
            )
            ->latest('id')
            ->paginate(10);

        $orders->getCollection()->transform(
            function ($order) {
                return [
                    'id' => $order->id,

                    'order_number' =>
                        $order->order_number,

                    'restaurant' => [
                        'id' =>
                            $order->restaurant?->id,

                        'name' =>
                            $order->restaurant?->restaurant_name,
                    ],

                    'customer' => [
                        'id' =>
                            $order->customer?->id,

                        'name' =>
                            $order->customer?->name,

                        'phone' =>
                            $order->customer?->phone,

                        'email' =>
                            $order->customer?->email,
                    ],

                    'rider' => [
                        'id' =>
                            $order->rider?->id,

                        'name' =>
                            $order->rider?->name,

                        'phone' =>
                            $order->rider?->phone,
                    ],

                    'delivery_address' =>
                        $order->deliveryAddress
                            ? [
                                'id' =>
                                    $order->deliveryAddress->id,

                                'label' =>
                                    $order->deliveryAddress->label,

                                'receiver_name' =>
                                    $order->deliveryAddress->receiver_name,

                                'receiver_phone' =>
                                    $order->deliveryAddress->receiver_phone,

                                'address' =>
                                    $order->deliveryAddress->address,

                                'landmark' =>
                                    $order->deliveryAddress->landmark,

                                'pincode' =>
                                    $order->deliveryAddress->pincode,

                                'latitude' =>
                                    $order->deliveryAddress->latitude,

                                'longitude' =>
                                    $order->deliveryAddress->longitude,
                            ]
                            : null,

                    'items' =>
                        $order->items->map(
                            function ($item) {
                                return [
                                    'id' =>
                                        $item->id,

                                    'menu_item_id' =>
                                        $item->menu_item_id,

                                    'name' =>
                                        $item->menu_item_name,

                                    'variant' =>
                                        $item->variant_name,

                                    'quantity' =>
                                        $item->quantity,

                                    'price' =>
                                        $item->unit_price,

                                    'total' =>
                                        $item->total_price,

                                    'special_instruction' =>
                                        $item->special_instruction,
                                ];
                            }
                        )->values(),

                    'amount' => [
                        'subtotal' =>
                            $order->subtotal,

                        'delivery_charge' =>
                            $order->delivery_charge,

                        'packing_charge' =>
                            $order->packing_charge,

                        'tax' =>
                            $order->tax,

                        'discount' =>
                            $order->discount,

                        'total_amount' =>
                            $order->total_amount,
                    ],

                    'payment' => [
                        'method' =>
                            $order->payment?->payment_method
                            ?? $order->payment_method,

                        'status' =>
                            $order->payment?->payment_status
                            ?? $order->payment_status,

                        'transaction_id' =>
                            $order->payment?->transaction_id,

                        'gateway' =>
                            $order->payment?->payment_gateway,

                        'paid_at' =>
                            $order->payment?->paid_at,
                    ],

                    'status' =>
                        $order->order_status,

                    'placed_at' =>
                        $order->placed_at,

                    'accepted_at' =>
                        $order->accepted_at,

                    'preparing_at' =>
                        $order->preparing_at,

                    'ready_for_pickup_at' =>
                        $order->ready_for_pickup_at,

                    'picked_up_at' =>
                        $order->picked_up_at,

                    'delivered_at' =>
                        $order->delivered_at,

                    'cancelled_at' =>
                        $order->cancelled_at,

                    'cancel_reason' =>
                        $order->cancel_reason,

                    'notes' =>
                        $order->notes,

                    // Flat keys kept for pages that read the
                    // older /admin/orders response shape
                    'customer_id' =>
                        $order->customer_id,

                    'customer_name' =>
                        $order->customer?->name,

                    'restaurant_id' =>
                        $order->restaurant_id,

                    'restaurant_name' =>
                        $order->restaurant?->restaurant_name,

                    'rider_id' =>
                        $order->rider_id,

                    'rider_name' =>
                        $order->rider?->name,

                    'total_amount' =>
                        $order->total_amount,

                    'payment_method' =>
                        $order->payment_method,

                    'order_status' =>
                        $order->order_status,
                ];
            }
        );

        return response()->json([
            'message' =>
                'Orders fetched successfully.',

            'data' =>
                $orders,
        ]);
    }


    /**
     * Get order details
     */
    public function show($id)
    {
        $order = RestaurantOrder::query()
            ->with([
                'restaurant:id,restaurant_name',
                'customer:id,name,phone,email',
                'rider:id,name,phone',
                'deliveryAddress:id,customer_id,label,receiver_name,receiver_phone,address,landmark,pincode,latitude,longitude',
                'items:id,order_id,menu_item_id,menu_item_name,variant_name,quantity,unit_price,total_price,special_instruction',
                'payment:id,order_id,payment_method,payment_status,order_amount,delivery_fee,packing_charge,tax,discount,total_amount,transaction_id,payment_gateway,paid_at',
                'statusHistories',
            ])
            ->find($id);

        if (!$order) {
            return response()->json([
                'message' =>
                    'Order not found.',
            ], 404);
        }


        return response()->json([
            'message' =>
                'Order details fetched successfully.',

            'data' => [

                'id' =>
                    $order->id,

                'order_number' =>
                    $order->order_number,


                'restaurant' => [
                    'id' =>
                        $order->restaurant?->id,

                    'name' =>
                        $order->restaurant?->restaurant_name,
                ],


                'customer' => [
                    'id' =>
                        $order->customer?->id,

                    'name' =>
                        $order->customer?->name,

                    'phone' =>
                        $order->customer?->phone,

                    'email' =>
                        $order->customer?->email,
                ],


                'rider' => [
                    'id' =>
                        $order->rider?->id,

                    'name' =>
                        $order->rider?->name,

                    'phone' =>
                        $order->rider?->phone,
                ],


                'delivery_address' =>
                    $order->deliveryAddress
                        ? [
                            'id' =>
                                $order->deliveryAddress->id,

                            'label' =>
                                $order->deliveryAddress->label,

                            'receiver_name' =>
                                $order->deliveryAddress->receiver_name,

                            'receiver_phone' =>
                                $order->deliveryAddress->receiver_phone,

                            'address' =>
                                $order->deliveryAddress->address,

                            'landmark' =>
                                $order->deliveryAddress->landmark,

                            'pincode' =>
                                $order->deliveryAddress->pincode,

                            'latitude' =>
                                $order->deliveryAddress->latitude,

                            'longitude' =>
                                $order->deliveryAddress->longitude,
                        ]
                        : null,


                'items' =>
                    $order->items->map(
                        function ($item) {
                            return [
                                'id' =>
                                    $item->id,

                                'menu_item_id' =>
                                    $item->menu_item_id,

                                'name' =>
                                    $item->menu_item_name,

                                'variant' =>
                                    $item->variant_name,

                                'quantity' =>
                                    $item->quantity,

                                'price' =>
                                    $item->unit_price,

                                'total' =>
                                    $item->total_price,

                                'special_instruction' =>
                                    $item->special_instruction,
                            ];
                        }
                    )->values(),


                'amount' => [
                    'subtotal' =>
                        $order->subtotal,

                    'delivery_charge' =>
                        $order->delivery_charge,

                    'packing_charge' =>
                        $order->packing_charge,

                    'tax' =>
                        $order->tax,

                    'discount' =>
                        $order->discount,

                    'total_amount' =>
                        $order->total_amount,
                ],


                'payment' => [
                    'method' =>
                        $order->payment?->payment_method
                        ?? $order->payment_method,

                    'status' =>
                        $order->payment?->payment_status
                        ?? $order->payment_status,

                    'transaction_id' =>
                        $order->payment?->transaction_id,

                    'gateway' =>
                        $order->payment?->payment_gateway,

                    'paid_at' =>
                        $order->payment?->paid_at,
                ],


                'status' =>
                    $order->order_status,

                'placed_at' =>
                    $order->placed_at,

                'accepted_at' =>
                    $order->accepted_at,

                'preparing_at' =>
                    $order->preparing_at,

                'ready_for_pickup_at' =>
                    $order->ready_for_pickup_at,

                'picked_up_at' =>
                    $order->picked_up_at,

                'delivered_at' =>
                    $order->delivered_at,

                'cancelled_at' =>
                    $order->cancelled_at,

                'cancel_reason' =>
                    $order->cancel_reason,

                'notes' =>
                    $order->notes,


                'timeline' =>
                    $order->statusHistories
                        ->map(
                            function ($history) {
                                return [
                                    'id' =>
                                        $history->id,

                                    'status' =>
                                        $history->status,

                                    'changed_by' =>
                                        $history->changed_by,

                                    'remarks' =>
                                        $history->remarks,

                                    'created_at' =>
                                        $history->created_at,
                                ];
                            }
                        )
                        ->values(),
            ],
        ]);
    }
}