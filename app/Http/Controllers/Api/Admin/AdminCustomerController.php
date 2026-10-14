<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\RestaurantOrder;
use Illuminate\Http\Request;

class AdminCustomerController extends Controller
{
    /**
     * Get customer list
     */
   public function index(Request $request)
{
    $customers = Customer::query()
        ->select([
            'id',
            'name',
            'phone',
            'email',
            'date_of_birth',
            'profile_photo',
                'status',
            'created_at',
            'updated_at',
        ])
        ->with([
            'addresses' => function ($query) {
                $query->where('is_default', true)
                    ->select([
                        'id',
                        'customer_id',
                        'label',
                        'receiver_name',
                        'receiver_phone',
                        'address',
                        'landmark',
                        'pincode',
                        'latitude',
                        'longitude',
                        'is_default',
                    ]);
            },
        ])
        ->latest('id')
        ->paginate(10);

        $customers->getCollection()->transform(function ($customer) {
    if ($customer->profile_photo) {
        $customer->profile_photo = asset(
            'storage/' . $customer->profile_photo
        );
    }

    return $customer;
});

    return response()->json([
        'message' => 'Customers fetched successfully.',
        'data' => $customers,
    ]);
} 

public function show($id)
{
    $customer = Customer::query()
        ->select([
            'id',
            'name',
            'phone',
            'email',
            'date_of_birth',
            'profile_photo',
                'status',
            'created_at',
            'updated_at',
        ])
        ->with([
            'addresses' => function ($query) {
                $query->select([
                    'id',
                    'customer_id',
                    'label',
                    'receiver_name',
                    'receiver_phone',
                    'address',
                    'landmark',
                    'pincode',
                    'latitude',
                    'longitude',
                    'is_default',
                    'created_at',
                    'updated_at',
                ]);
            },
        ])
        ->find($id);

    if (!$customer) {
        return response()->json([
            'message' => 'Customer not found.',
        ], 404);
    }

    if ($customer->profile_photo) {
    $customer->profile_photo = asset(
        'storage/' . $customer->profile_photo
    );
}


    return response()->json([
        'message' => 'Customer details fetched successfully.',
        'data' => $customer,
    ]);
}

/**
 * Update customer
 */
public function update(Request $request, $id)
{
    $customer = Customer::find($id);

    if (!$customer) {
        return response()->json([
            'message' => 'Customer not found.',
        ], 404);
    }

    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'phone' => ['required', 'string', 'max:20'],
        'email' => ['nullable', 'email', 'max:255'],
        'address' => ['nullable', 'string', 'max:1000'],
       'status' => ['sometimes', 'in:active,blocked'],
    ]);

    $customer->name = $validated['name'];
    $customer->phone = $validated['phone'];
    $customer->email = $validated['email'] ?? null;
    if (array_key_exists('status', $validated)) {
    $customer->status = $validated['status'];

    // If customer is blocked, immediately revoke all login tokens
    if ($validated['status'] === 'blocked') {
        $customer->tokens()->delete();
    }
}
    $customer->save();

 if (array_key_exists('address', $validated) && $validated['address'] !== null) {
    $defaultAddress = $customer->addresses()
        ->where('is_default', true)
        ->first();

    if ($defaultAddress) {
        $defaultAddress->update([
            'address' => $validated['address'],
        ]);
    } else {
        $customer->addresses()->create([
            'label' => 'Home',
            'receiver_name' => $customer->name,
            'receiver_phone' => $customer->phone,
            'address' => $validated['address'],
            'is_default' => true,
        ]);
    }
}

    $customer->load([
        'addresses' => function ($query) {
            $query->where('is_default', true)
                ->select([
                    'id',
                    'customer_id',
                    'label',
                    'receiver_name',
                    'receiver_phone',
                    'address',
                    'landmark',
                    'pincode',
                    'latitude',
                    'longitude',
                    'is_default',
                ]);
        },
    ]);

    return response()->json([
        'message' => 'Customer updated successfully.',
        'data' => $customer,
    ]);
}

/**
 * Get all customer orders
 */
public function allOrders(Request $request)
{
    $orders = RestaurantOrder::query()
        ->select([
            'id',
            'customer_id',
            'restaurant_id',
            'rider_id',
            'order_number',
            'total_amount',
            'payment_method',
            'order_status',
            'placed_at',
            'delivered_at',
            'cancel_reason',
        ])
        ->with([
            'customer:id,name',
            'restaurant:id,restaurant_name',
            'rider:id,name',
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

   $orders->getCollection()->transform(function ($order) {
    return [
        'id' => $order->id,

        'order_number' => $order->order_number,

        'customer_id' => $order->customer_id,
        'customer_name' => $order->customer?->name,

        'restaurant_id' => $order->restaurant_id,
        'restaurant_name' => $order->restaurant?->restaurant_name,

        'rider_id' => $order->rider_id,
        'rider_name' => $order->rider?->name,

        'total_amount' => $order->total_amount,

        'payment_method' => $order->payment_method,

        'order_status' => $order->order_status,

        'placed_at' => $order->placed_at,

        'delivered_at' => $order->delivered_at,

        'cancel_reason' =>
            $order->order_status === 'cancelled'
                ? $order->cancel_reason
                : null,
    ];
});

    return response()->json([
        'message' => 'All customer orders fetched successfully.',
        'data' => $orders,
    ]);
}

/**
 * Get all customer order details
 */
public function allOrderDetails($orderId)
{
    $order = RestaurantOrder::query()
        ->where('id', $orderId)
        ->select([
            'id',
            'customer_id',
            'restaurant_id',
            'rider_id',
            'delivery_address_id',
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
            'delivered_at',
            'cancel_reason',
            'notes',
        ])
        ->with([
            'customer:id,name,phone,email',

            'restaurant:id,restaurant_name',

            'restaurant.address:id,restaurant_id,full_address,shop_plot_number,floor,building_name,pincode,complete_address,latitude,longitude,landmark',

            'rider:id,name,phone',

            'deliveryAddress:id,customer_id,label,receiver_name,receiver_phone,address,landmark,pincode,latitude,longitude',

            'items:id,order_id,menu_item_name,variant_name,quantity,unit_price,total_price,special_instruction',

            'payment:id,order_id,payment_method,payment_status,transaction_id,payment_gateway,paid_at',

            'statusHistories:id,order_id,status,changed_by,remarks,created_at',
        ])
        ->first();

    if (!$order) {
        return response()->json([
            'message' => 'Order not found.',
        ], 404);
    }

    return response()->json([
        'message' => 'Order details fetched successfully.',

        'data' => [

            /*
            |--------------------------------------------------------------------------
            | ORDER
            |--------------------------------------------------------------------------
            */

            'id' => $order->id,

            'order_number' => $order->order_number,

            'order_status' => $order->order_status,

            'placed_at' => $order->placed_at,

            'delivered_at' => $order->delivered_at,

            'cancel_reason' =>
                $order->order_status === 'cancelled'
                    ? $order->cancel_reason
                    : null,

            'notes' => $order->notes,


            /*
            |--------------------------------------------------------------------------
            | CUSTOMER
            |--------------------------------------------------------------------------
            */

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


            /*
            |--------------------------------------------------------------------------
            | RESTAURANT
            |--------------------------------------------------------------------------
            */

            'restaurant' => [

                'id' =>
                    $order->restaurant?->id,

                'name' =>
                    $order->restaurant?->restaurant_name,

                'address' =>
                    $order->restaurant?->address
                        ? [

                            'id' =>
                                $order->restaurant->address->id,

                            'full_address' =>
                                $order->restaurant->address->full_address,

                            'shop_plot_number' =>
                                $order->restaurant->address->shop_plot_number,

                            'floor' =>
                                $order->restaurant->address->floor,

                            'building_name' =>
                                $order->restaurant->address->building_name,

                            'complete_address' =>
                                $order->restaurant->address->complete_address,

                            'landmark' =>
                                $order->restaurant->address->landmark,

                            'pincode' =>
                                $order->restaurant->address->pincode,

                            'latitude' =>
                                $order->restaurant->address->latitude,

                            'longitude' =>
                                $order->restaurant->address->longitude,

                        ]
                        : null,
            ],


            /*
            |--------------------------------------------------------------------------
            | RIDER
            |--------------------------------------------------------------------------
            */

            'rider' => [

                'id' =>
                    $order->rider?->id,

                'name' =>
                    $order->rider?->name,

                'phone' =>
                    $order->rider?->phone,
            ],


            /*
            |--------------------------------------------------------------------------
            | DELIVERY ADDRESS
            |--------------------------------------------------------------------------
            */

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


            /*
            |--------------------------------------------------------------------------
            | ORDER ITEMS
            |--------------------------------------------------------------------------
            */

            'items' =>
                $order->items
                    ->map(function ($item) {

                        return [

                            'id' =>
                                $item->id,

                            'food_name' =>
                                $item->menu_item_name,

                            'variant' =>
                                $item->variant_name,

                            'quantity' =>
                                $item->quantity,

                            'unit_price' =>
                                $item->unit_price,

                            'total_price' =>
                                $item->total_price,

                            'special_instruction' =>
                                $item->special_instruction,

                        ];

                    })
                    ->values(),


            /*
            |--------------------------------------------------------------------------
            | AMOUNT
            |--------------------------------------------------------------------------
            */

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


            /*
            |--------------------------------------------------------------------------
            | PAYMENT
            |--------------------------------------------------------------------------
            */

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


            /*
            |--------------------------------------------------------------------------
            | STATUS TIMELINE
            |--------------------------------------------------------------------------
            */

            'timeline' =>
                $order->statusHistories
                    ->map(function ($history) {

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

                    })
                    ->values(),
        ],
    ]);
}

/**
 * Get customer order history
 */
public function orders($customerId)
{
    $orders = RestaurantOrder::query()
        ->where('customer_id', $customerId)
        ->select([
            'id',
            'customer_id',
            'restaurant_id',
            'rider_id',
            'order_number',
            'total_amount',
            'payment_method',
            'order_status',
            'placed_at',
            'delivered_at',
            'cancel_reason',
        ])
        ->with([
    'customer:id,name',
    'restaurant:id,restaurant_name',
    'rider:id,name',
    'items:id,order_id,menu_item_name,variant_name,quantity',
])
        ->latest('id')
        ->paginate(10);

   $orders->getCollection()->transform(function ($order) {
    return [
        'id' => $order->id,

        'order_number' => $order->order_number,

        'customer_id' => $order->customer_id,
        'customer_name' => $order->customer?->name,

        'restaurant_id' => $order->restaurant_id,
        'restaurant_name' => $order->restaurant?->restaurant_name,
        'items' => $order->items
    ->pluck('menu_item_name')
    ->filter()
    ->implode(', '),

        'rider_id' => $order->rider_id,
        'rider_name' => $order->rider?->name,

        'total_amount' => $order->total_amount,

        'payment_method' => $order->payment_method,

        'order_status' => $order->order_status,

        'placed_at' => $order->placed_at,

        'delivered_at' => $order->delivered_at,

        'cancel_reason' =>
            $order->order_status === 'cancelled'
                ? $order->cancel_reason
                : null,
    ];
});

    return response()->json([
        'message' => 'Customer order history fetched successfully.',
        'data' => $orders,
    ]);
}

/**
 * Get customer order details
 */
public function orderDetails($customerId, $orderId)
{
    $order = RestaurantOrder::query()
        ->where('id', $orderId)
        ->where('customer_id', $customerId)
        ->select([
            'id',
            'customer_id',
            'restaurant_id',
            'rider_id',
            'delivery_address_id',
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
            'delivered_at',
            'cancel_reason',
        ])
        ->with([
            'customer:id,name,phone',
            'restaurant:id,restaurant_name',
            'rider:id,name,phone',
            'deliveryAddress:id,customer_id,label,receiver_name,receiver_phone,address,landmark,pincode',
            'items:id,order_id,menu_item_name,variant_name,quantity,unit_price,total_price',
            'payment:id,order_id,payment_method,payment_status',
        ])
        ->first();

    if (!$order) {
        return response()->json([
            'message' => 'Order not found for this customer.',
        ], 404);
    }

    return response()->json([
        'message' => 'Customer order details fetched successfully.',
        'data' => [
            'order_number' => $order->order_number,

            'order_status' => $order->order_status,
            'placed_at' => $order->placed_at,
            'delivered_at' => $order->delivered_at,
            'cancel_reason' => $order->order_status === 'cancelled'
                ? $order->cancel_reason
                : null,

            'customer' => [
                'name' => $order->customer?->name,
                'phone' => $order->customer?->phone,
            ],

            'restaurant' => [
                'name' => $order->restaurant?->restaurant_name,
            ],

            'rider' => [
                'name' => $order->rider?->name,
                'phone' => $order->rider?->phone,
            ],

            'delivery_address' => $order->deliveryAddress ? [
                'label' => $order->deliveryAddress->label,
                'receiver_name' => $order->deliveryAddress->receiver_name,
                'receiver_phone' => $order->deliveryAddress->receiver_phone,
                'address' => $order->deliveryAddress->address,
                'landmark' => $order->deliveryAddress->landmark,
                'pincode' => $order->deliveryAddress->pincode,
            ] : null,

            'items' => $order->items->map(function ($item) {
                return [
                    'food_name' => $item->menu_item_name,
                    'variant' => $item->variant_name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                ];
            })->values(),

            'amount' => [
                'subtotal' => $order->subtotal,
                'delivery_charge' => $order->delivery_charge,
                'packing_charge' => $order->packing_charge,
                'tax' => $order->tax,
                'discount' => $order->discount,
                'total_amount' => $order->total_amount,
            ],

            'payment' => [
                'method' => $order->payment?->payment_method ?? $order->payment_method,
                'status' => $order->payment?->payment_status ?? $order->payment_status,
            ],
        ],
    ]);
}

}