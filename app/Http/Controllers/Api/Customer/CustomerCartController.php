<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CustomerCartController extends Controller
{
   public function index(Request $request)
{
    $customer = $request->user();

    $cart = \App\Models\CustomerCart::query()
        ->where('customer_id', $customer->id)
        ->with([
            'restaurant',
            'items.menuItem',
        ])
        ->first();

    if (!$cart) {
        return response()->json([
            'success' => true,
            'message' => 'Cart is empty.',
            'data' => [
                'cart' => null,
            ],
        ], 200);
    }

    return response()->json([
        'success' => true,
        'message' => 'Cart fetched successfully.',
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

public function addItem(Request $request)
{
    $customer = $request->user();

    $validated = $request->validate([
        'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
        'menu_item_id' => ['required', 'integer', 'exists:restaurant_menu_items,id'],
        'quantity' => ['required', 'integer', 'min:1'],
        'special_instruction' => ['nullable', 'string'],
    ]);

    $restaurant = \App\Models\Restaurant::query()
        ->where('id', $validated['restaurant_id'])
        ->where('status', 'approved')
        ->first();

    if (!$restaurant) {
        return response()->json([
            'success' => false,
            'message' => 'Restaurant not found.',
        ], 404);
    }

    $menuItem = \App\Models\RestaurantMenuItem::query()
        ->where('id', $validated['menu_item_id'])
        ->where('restaurant_id', $restaurant->id)
        ->where('is_active', true)
        ->where('is_available', true)
        ->first();

    if (!$menuItem) {
        return response()->json([
            'success' => false,
            'message' => 'Menu item not found or unavailable.',
        ], 404);
    }

    $unitPrice = $menuItem->discount_price !== null
        ? $menuItem->discount_price
        : $menuItem->price;

    $cart = \App\Models\CustomerCart::query()
        ->where('customer_id', $customer->id)
        ->first();

    if ($cart && (int) $cart->restaurant_id !== (int) $restaurant->id) {
        return response()->json([
            'success' => false,
            'message' => 'Your cart contains items from another restaurant.',
            'data' => [
                'cart_restaurant_id' => $cart->restaurant_id,
                'requested_restaurant_id' => $restaurant->id,
                'requires_cart_clear' => true,
            ],
        ], 409);
    }

    if (!$cart) {
        $cart = \App\Models\CustomerCart::create([
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
        ]);
    }

    $cartItem = $cart->items()
        ->where('menu_item_id', $menuItem->id)
        ->first();

    if ($cartItem) {
        $cartItem->quantity += $validated['quantity'];
        $cartItem->unit_price = $unitPrice;
        $cartItem->total_price =
            $cartItem->quantity * $unitPrice;

        if (array_key_exists('special_instruction', $validated)) {
            $cartItem->special_instruction =
                $validated['special_instruction'];
        }

        $cartItem->save();
    } else {
        $cartItem = $cart->items()->create([
            'menu_item_id' => $menuItem->id,
            'menu_item_name' => $menuItem->name,
            'quantity' => $validated['quantity'],
            'unit_price' => $unitPrice,
            'total_price' => $validated['quantity'] * $unitPrice,
            'special_instruction' =>
                $validated['special_instruction'] ?? null,
        ]);
    }

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

    $cart->load([
        'restaurant',
        'items.menuItem',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Item added to cart successfully.',
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
                        'special_instruction' =>
                            $item->special_instruction,
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

public function updateItem(Request $request, $itemId)
{
    $customer = $request->user();

$validated = $request->validate([
    'quantity' => ['required', 'integer', 'min:1'],
]);

$cartItem = \App\Models\CustomerCartItem::query()
    ->where('id', $itemId)
    ->whereHas('cart', function ($query) use ($customer) {
        $query->where('customer_id', $customer->id);
    })
    ->with('cart')
    ->first();

if (!$cartItem) {
    return response()->json([
        'success' => false,
        'message' => 'Cart item not found.',
    ], 404);
}

$cartItem->quantity = $validated['quantity'];
$cartItem->total_price =
    $cartItem->quantity * $cartItem->unit_price;

$cartItem->save();

$cart = $cartItem->cart;

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

$cart->load([
    'restaurant',
    'items.menuItem',
]);

return response()->json([
    'success' => true,
    'message' => 'Cart item updated successfully.',
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
                    'special_instruction' =>
                        $item->special_instruction,
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

public function removeItem(Request $request, $itemId)
{
    $customer = $request->user();

$cartItem = \App\Models\CustomerCartItem::query()
    ->where('id', $itemId)
    ->whereHas('cart', function ($query) use ($customer) {
        $query->where('customer_id', $customer->id);
    })
    ->with('cart')
    ->first();

if (!$cartItem) {
    return response()->json([
        'success' => false,
        'message' => 'Cart item not found.',
    ], 404);
}

$cart = $cartItem->cart;

$cartItem->delete();

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

$remainingItems = $cart->items()->count();

if ($remainingItems === 0) {
    $cart->delete();

    return response()->json([
        'success' => true,
        'message' => 'Item removed and cart is now empty.',
        'data' => [
            'cart' => null,
        ],
    ], 200);
}

$cart->save();

$cart->load([
    'restaurant',
    'items.menuItem',
]);

return response()->json([
    'success' => true,
    'message' => 'Item removed from cart successfully.',
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
                    'special_instruction' =>
                        $item->special_instruction,
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

public function clear(Request $request)
{
    $customer = $request->user();

$cart = \App\Models\CustomerCart::query()
    ->where('customer_id', $customer->id)
    ->first();

if (!$cart) {
    return response()->json([
        'success' => true,
        'message' => 'Cart is already empty.',
        'data' => [
            'cart' => null,
        ],
    ], 200);
}

$cart->items()->delete();
$cart->delete();

return response()->json([
    'success' => true,
    'message' => 'Cart cleared successfully.',
    'data' => [
        'cart' => null,
    ],
], 200);
}

}
