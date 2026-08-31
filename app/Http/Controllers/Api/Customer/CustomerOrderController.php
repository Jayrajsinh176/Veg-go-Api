<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Models\CustomerCart;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantOrderPayment;
use App\Models\RestaurantMenuItem;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerOrderController extends Controller
{
    /**
     * Place a customer order.
     */
    public function store(Request $request)
    {
        
        $customer = $request->user();

        

        $validated = $request->validate([
            'delivery_address_id' => 'required|integer',
            'payment_method' => 'required|in:cash,online',
        ]);

        /*
         * Get customer's cart with items.
         */
        $cart = CustomerCart::query()
            ->where('customer_id', $customer->id)
            ->with('items')
            ->first();

        if (!$cart || $cart->items->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Your cart is empty.',
            ], 422);
        }

        /*
         * Make sure selected address belongs to customer.
         */
        $address = CustomerAddress::query()
            ->where('id', $validated['delivery_address_id'])
            ->where('customer_id', $customer->id)
            ->first();

        if (!$address) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery address not found.',
            ], 404);
        }

        /*
         * Start database transaction.
         */
        DB::beginTransaction();

        try {

            /*
             * Generate unique order number.
             */
            do {
                $orderNumber = 'VG' . now()->format('YmdHis') . rand(100, 999);
            } while (
                RestaurantOrder::where('order_number', $orderNumber)->exists()
            );

            /*
             * Create restaurant order.
             */
            $order = RestaurantOrder::create([
                'restaurant_id' => $cart->restaurant_id,
                'customer_id' => $customer->id,
                'delivery_address_id' => $address->id,

                'order_number' => $orderNumber,

                'subtotal' => $cart->subtotal,
                'delivery_charge' => $cart->delivery_charge ?? 0,
                'packing_charge' => $cart->packing_charge ?? 0,
                'tax' => $cart->tax ?? 0,
                'discount' => $cart->discount ?? 0,
                'total_amount' => $cart->total_amount,

                'payment_method' => $validated['payment_method'],
                'payment_status' => 'pending',

                'order_status' => 'pending',
                'placed_at' => now(),
            ]);

            /*
             * Copy cart items into order items.
             */
            foreach ($cart->items as $cartItem) {
                RestaurantOrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $cartItem->menu_item_id,
                    'menu_item_name' => $cartItem->menu_item_name,
                    'variant_name' => null,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $cartItem->unit_price,
                    'total_price' => $cartItem->total_price,
                    'special_instruction' => $cartItem->special_instruction,
                ]);
            }

            /*
             * Create payment record.
             */
            RestaurantOrderPayment::create([
                'order_id' => $order->id,
                'payment_method' => $validated['payment_method'] === 'cash'
    ? 'COD'
    : 'ONLINE',
                'payment_status' => 'PENDING',

                'order_amount' => $cart->subtotal,
                'delivery_fee' => $cart->delivery_charge ?? 0,
                'packing_charge' => $cart->packing_charge ?? 0,
                'tax' => $cart->tax ?? 0,
                'discount' => $cart->discount ?? 0,
                'total_amount' => $cart->total_amount,
            ]);

            /*
             * For COD:
             * Order can be created immediately.
             *
             * For ONLINE:
             * Payment remains PENDING until payment gateway
             * confirms the payment.
             */
           if ($validated['payment_method'] === 'cash') {
                $order->update([
                    'payment_status' => 'pending',
                ]);
            }

            /*
             * Remove cart after successful order creation.
             */
            $cart->items()->delete();
            $cart->delete();

            DB::commit();

            /*
             * Load complete order response.
             */
            $order->load([
                'restaurant',
                'items',
                'payment',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully.',
                'data' => [
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,

                        'restaurant' => [
                            'id' => $order->restaurant?->id,
                            'name' => $order->restaurant?->restaurant_name,
                        ],

                        'delivery_address_id' => $order->delivery_address_id,

                        'items' => $order->items->map(function ($item) {
                            return [
                                'id' => $item->id,
                                'menu_item_id' => $item->menu_item_id,
                                'name' => $item->menu_item_name,
                                'quantity' => $item->quantity,
                                'unit_price' => $item->unit_price,
                                'total_price' => $item->total_price,
                            ];
                        })->values(),

                        'subtotal' => $order->subtotal,
                        'delivery_charge' => $order->delivery_charge,
                        'packing_charge' => $order->packing_charge,
                        'tax' => $order->tax,
                        'discount' => $order->discount,
                        'total_amount' => $order->total_amount,

                        'payment_method' => $order->payment_method,
                        'payment_status' => $order->payment_status,
                        'order_status' => $order->order_status,
                        'delivery_otp' => $order->delivery_otp,


                        'placed_at' => $order->placed_at,
                    ],
                ],
            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Unable to place order.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
 * Get customer order details.
 */
public function show(Request $request, $id)
{
    $customer = $request->user();

    $order = RestaurantOrder::query()
        ->where('id', $id)
        ->where('customer_id', $customer->id)
        ->with([
            'restaurant',
            'items',
            'payment',
        ])
        ->first();

    if (!$order) {
        return response()->json([
            'success' => false,
            'message' => 'Order not found.',
        ], 404);
    }

    /*
 * Generate delivery OTP when the rider has picked up the order.
 * OTP is generated only once.
 */
if (
    $order->order_status === 'picked_up' &&
    empty($order->delivery_otp)
) {
    $order->delivery_otp = str_pad(
        random_int(1000, 9999),
        4,
        '0',
        STR_PAD_LEFT
    );

    $order->delivery_otp_expires_at = now()->addMinutes(30);

    $order->save();
}


    /*
     * Get the delivery address separately.
     * This makes sure the address also belongs to the customer.
     */
    $address = CustomerAddress::query()
        ->where('id', $order->delivery_address_id)
        ->where('customer_id', $customer->id)
        ->first();

    return response()->json([
        'success' => true,
        'message' => 'Order details fetched successfully.',
        'data' => [
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,

                'restaurant' => [
                    'id' => $order->restaurant?->id,
                    'name' => $order->restaurant?->restaurant_name,
                ],

                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'menu_item_id' => $item->menu_item_id,
                        'name' => $item->menu_item_name,
                        'variant_name' => $item->variant_name,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'total_price' => $item->total_price,
                        'special_instruction' => $item->special_instruction,
                    ];
                })->values(),

                'subtotal' => $order->subtotal,
                'delivery_charge' => $order->delivery_charge,
                'packing_charge' => $order->packing_charge,
                'tax' => $order->tax,
                'discount' => $order->discount,
                'total_amount' => $order->total_amount,

                'payment' => [
                    'method' => $order->payment_method,
                    'status' => $order->payment_status,
                    'transaction_id' => $order->payment?->transaction_id,
                    'payment_gateway' => $order->payment?->payment_gateway,
                    'paid_at' => $order->payment?->paid_at,
                ],

                'order_status' => $order->order_status,
                'delivery_otp' => $order->delivery_otp,
                'delivery_otp_expires_at' => $order->delivery_otp_expires_at,

                'delivery_address' => $address ? [
                    'id' => $address->id,
                    'label' => $address->label,
                    'receiver_name' => $address->receiver_name,
                    'receiver_phone' => $address->receiver_phone,
                    'address' => $address->address,
                    'landmark' => $address->landmark,
                    'pincode' => $address->pincode,
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                ] : null,

                /*
                 * Temporary estimated delivery time.
                 * We can make this dynamic later.
                 */
                'estimated_delivery_minutes' => 35,

                'placed_at' => $order->placed_at,
                'accepted_at' => $order->accepted_at,
                'preparing_at' => $order->preparing_at,
                'ready_for_pickup_at' => $order->ready_for_pickup_at,
                'picked_up_at' => $order->picked_up_at,
                'delivered_at' => $order->delivered_at,
                'cancelled_at' => $order->cancelled_at,
                'cancel_reason' => $order->cancel_reason,
            ],
        ],
    ], 200);
}

/**
 * Get customer's order history.
 */
public function index(Request $request)
{
    $customer = $request->user();

    $orders = RestaurantOrder::query()
        ->where('customer_id', $customer->id)
        ->with([
            'restaurant',
            'items',
            'payment',
            'rider',
        ])
        ->latest('id')
        ->get();

    return response()->json([
        'success' => true,
        'message' => 'Order history fetched successfully.',
        'data' => [
            'orders' => $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,

                    'restaurant' => [
                        'id' => $order->restaurant?->id,
                        'name' => $order->restaurant?->restaurant_name,
                    ],

                    'rider' => $order->rider ? [
                        'id' => $order->rider->id,
                        'name' => $order->rider->name,
                        'phone' => $order->rider->phone,
                        'status' => $order->rider->status,
                        'is_online' => $order->rider->is_online,
                    ] : null,

                    'items' => $order->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'menu_item_id' => $item->menu_item_id,
                            'name' => $item->menu_item_name,
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'total_price' => $item->total_price,
                        ];
                    })->values(),

                    'subtotal' => $order->subtotal,
                    'delivery_charge' => $order->delivery_charge,
                    'packing_charge' => $order->packing_charge,
                    'tax' => $order->tax,
                    'discount' => $order->discount,
                    'total_amount' => $order->total_amount,

                    'payment_method' => $order->payment_method,
                    'payment_status' => $order->payment_status,

                    'order_status' => $order->order_status,

                    'placed_at' => $order->placed_at,
                    'delivered_at' => $order->delivered_at,
                    'cancelled_at' => $order->cancelled_at,
                ];
            })->values(),
        ],
    ], 200);
}

public function reorder(Request $request, $id)
{
    $customer = $request->user();

    $order = RestaurantOrder::with([
        'items',
        'restaurant',
    ])
    ->where('id', $id)
    ->where('customer_id', $customer->id)
    ->first();

    if (!$order) {
        return response()->json([
            'success' => false,
            'message' => 'Order not found.',
        ], 404);
    }

    /*
     * Check all old order items are still available.
     */
    $menuItems = [];

    foreach ($order->items as $orderItem) {

        $menuItem = \App\Models\RestaurantMenuItem::query()
            ->where('id', $orderItem->menu_item_id)
            ->where('restaurant_id', $order->restaurant_id)
            ->where('is_active', true)
            ->where('is_available', true)
            ->first();

        if (!$menuItem) {
            return response()->json([
                'success' => false,
                'message' => 'Some items from this order are no longer available.',
                'data' => [
                    'unavailable_item' => [
                        'menu_item_id' => $orderItem->menu_item_id,
                        'name' => $orderItem->menu_item_name,
                    ],
                ],
            ], 422);
        }

        $menuItems[$orderItem->menu_item_id] = $menuItem;
    }

    /*
     * Get customer's existing cart.
     */
    $cart = CustomerCart::query()
        ->where('customer_id', $customer->id)
        ->first();

    /*
     * Do not mix restaurants in the same cart.
     */
    if ($cart && (int) $cart->restaurant_id !== (int) $order->restaurant_id) {
        return response()->json([
            'success' => false,
            'message' => 'Your cart contains items from another restaurant.',
            'data' => [
                'cart_restaurant_id' => $cart->restaurant_id,
                'requested_restaurant_id' => $order->restaurant_id,
                'requires_cart_clear' => true,
            ],
        ], 409);
    }

    /*
     * Create cart if customer does not have one.
     */
    if (!$cart) {
        $cart = CustomerCart::create([
            'customer_id' => $customer->id,
            'restaurant_id' => $order->restaurant_id,
        ]);
    }

    /*
     * Add old order items to cart.
     */
    foreach ($order->items as $orderItem) {

        $menuItem = $menuItems[$orderItem->menu_item_id];

        /*
         * Use current menu price, not old order price.
         */
        $unitPrice = $menuItem->discount_price !== null
            ? $menuItem->discount_price
            : $menuItem->price;

        $cartItem = $cart->items()
            ->where('menu_item_id', $menuItem->id)
            ->first();

        if ($cartItem) {

            $cartItem->quantity += $orderItem->quantity;

            $cartItem->unit_price = $unitPrice;

            $cartItem->total_price =
                $cartItem->quantity * $unitPrice;

            $cartItem->save();

        } else {

            $cart->items()->create([
                'menu_item_id' => $menuItem->id,
                'menu_item_name' => $menuItem->name,
                'quantity' => $orderItem->quantity,
                'unit_price' => $unitPrice,
                'total_price' => $orderItem->quantity * $unitPrice,
                'special_instruction' => null,
            ]);
        }
    }

    /*
     * Recalculate cart totals.
     */
    $cart->subtotal = $cart->items()->sum('total_price');

    $cart->delivery_charge = $cart->delivery_charge ?? 0;
    $cart->packing_charge = $cart->packing_charge ?? 0;
    $cart->discount = $cart->discount ?? 0;
    $cart->tax = $cart->tax ?? 0;

    $cart->total_amount =
        $cart->subtotal
        + $cart->delivery_charge
        + $cart->packing_charge
        + $cart->tax
        - $cart->discount;

    $cart->save();

    /*
     * Load complete cart.
     */
    $cart->load([
        'restaurant',
        'items.menuItem',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Order items added to cart successfully.',
        'data' => [
            'cart' => [
                'id' => $cart->id,

                'restaurant' => [
                    'id' => $cart->restaurant?->id,
                    'name' => $cart->restaurant?->restaurant_name,
                ],

                'items' => $cart->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'menu_item_id' => $item->menu_item_id,
                        'name' => $item->menu_item_name,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'total_price' => $item->total_price,
                        'special_instruction' => $item->special_instruction,
                        'image' => $item->menuItem?->image,
                    ];
                })->values(),

                'subtotal' => $cart->subtotal,
                'delivery_charge' => $cart->delivery_charge,
                'packing_charge' => $cart->packing_charge,
                'discount' => $cart->discount,
                'tax' => $cart->tax,
                'total_amount' => $cart->total_amount,
            ],
        ],
    ], 200);
}

}