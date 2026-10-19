<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CustomerCartController extends Controller
{

    private const FOOD_GST_RATE = 5;
    private const SERVICE_GST_RATE = 18;

    private const DELIVERY_CHARGE = 9; // fallback for config('restaurant.delivery_charge')
    private const PACKING_CHARGE = 10;
    private const PLATFORM_FEE = 15;

    // Public so offer price changes can refresh existing carts (RestaurantOfferService)
    public function recalculateCartTotals($cart)
{
    // Recalculate food subtotal
    $cart->subtotal = (float) $cart->items()->sum('total_price');

    $settings = \App\Models\PlatformSetting::getSettings();

    // Charges from configuration and settings
    $cart->delivery_charge = config('restaurant.delivery_charge', self::DELIVERY_CHARGE);
    $cart->packing_charge = self::PACKING_CHARGE;
    $cart->platform_fee = (float) ($settings->platform_fee ?? self::PLATFORM_FEE);

    // Small order fee: if subtotal is below configured threshold, apply extra fee
    $smallOrderThreshold = (float) ($settings->small_order_threshold ?? 250.00);
    $smallOrderFee = (float) ($settings->small_order_fee ?? 10.00);
    $cart->small_order_fee = ($cart->subtotal > 0 && $cart->subtotal < $smallOrderThreshold)
        ? $smallOrderFee
        : 0.00;

    /*
    |--------------------------------------------------------------------------
    | Recalculate Coupon Discount
    |--------------------------------------------------------------------------
    */

    $discount = 0;

    if ($cart->coupon_code) {

        $coupon = \App\Models\Coupon::query()
            ->where('code', $cart->coupon_code)
            ->where('is_active', true)
            ->first();

        if ($coupon) {

            // Check minimum order amount
            if ($cart->subtotal >= $coupon->min_order_amount) {

                if ($coupon->discount_type === 'percentage') {

                    $discount = round(
                        ($cart->subtotal * $coupon->discount_value) / 100,
                        2
                    );

                    // Maximum discount limit
                    if ($coupon->max_discount_amount !== null) {
                        $discount = min(
                            $discount,
                            $coupon->max_discount_amount
                        );
                    }

                } else {

                    // Fixed discount
                    $discount = min(
                        $coupon->discount_value,
                        $cart->subtotal
                    );
                }

            } else {

                // Minimum order no longer satisfied
                $cart->coupon_code = null;
                $cart->discount_type = null;
                $cart->discount_value = 0;
            }

        } else {

            // Coupon no longer valid
            $cart->coupon_code = null;
            $cart->discount_type = null;
            $cart->discount_value = 0;
        }
    }

    $cart->discount = $discount;

    /*
    |--------------------------------------------------------------------------
    | GST
    |--------------------------------------------------------------------------
    */

    // Food GST is calculated after discount
    $discountedFoodAmount =
        $cart->subtotal - $cart->discount;

    $foodGst = round(
        ($discountedFoodAmount * self::FOOD_GST_RATE) / 100,
        2
    );

    // Service GST
    $deliveryGst = round(
        ($cart->delivery_charge * self::SERVICE_GST_RATE) / 100,
        2
    );

    $packingGst = round(
        ($cart->packing_charge * self::SERVICE_GST_RATE) / 100,
        2
    );

    $platformGst = round(
        ($cart->platform_fee * self::SERVICE_GST_RATE) / 100,
        2
    );

    // Total GST
    $cart->tax =
        $foodGst
        + $deliveryGst
        + $packingGst
        + $platformGst;

    // Final total
    $cart->total_amount =
        $cart->subtotal
        + $cart->delivery_charge
        + $cart->packing_charge
        + $cart->platform_fee
        + (float) ($cart->small_order_fee ?? 0.00)
        + $cart->tax
        - $cart->discount;

    return $cart;
}

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

    $settings = \App\Models\PlatformSetting::getSettings();

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

                'subtotal' => (float) $cart->subtotal,
                'delivery_charge' => (float) $cart->delivery_charge,
                'packing_charge' => (float) $cart->packing_charge,
                'platform_fee' => (float) $cart->platform_fee,
                'small_order_fee' => (float) ($cart->small_order_fee ?? 0.00),
                'discount' => (float) $cart->discount,
                'tax' => (float) $cart->tax,
                'total_amount' => (float) $cart->total_amount,

                'checkout_rules' => [
                    'min_order_value' => (float) ($settings->minimum_order_value ?? 99.00),
                    'is_min_order_met' => (float) $cart->subtotal >= (float) ($settings->minimum_order_value ?? 99.00),
                    'min_order_remaining' => max(0, round((float) ($settings->minimum_order_value ?? 99.00) - (float) $cart->subtotal, 2)),

                    'small_order_threshold' => (float) ($settings->small_order_threshold ?? 250.00),
                    'small_order_fee' => (float) ($settings->small_order_fee ?? 10.00),
                    'has_small_order_fee' => (float) ($cart->small_order_fee ?? 0.00) > 0,
                    'small_order_remaining' => (float) $cart->subtotal < (float) ($settings->small_order_threshold ?? 250.00)
                        ? max(0, round((float) ($settings->small_order_threshold ?? 250.00) - (float) $cart->subtotal, 2))
                        : 0.00,

                    'free_delivery_above' => (float) ($settings->free_delivery_above ?? 499.00),
                    'free_delivery_remaining' => max(0, round((float) ($settings->free_delivery_above ?? 499.00) - (float) $cart->subtotal, 2)),

                    'is_cod_allowed' => (bool) (($settings->cod_enabled ?? true) && ((float) $cart->total_amount <= (float) ($settings->max_cod_order_amount ?? 2000.00))),
                    'max_cod_order_amount' => (float) ($settings->max_cod_order_amount ?? 2000.00),
                    'cod_enabled' => (bool) ($settings->cod_enabled ?? true),
                    'online_payment_enabled' => (bool) ($settings->online_payment_enabled ?? true),
                    'maintenance_mode' => (bool) ($settings->maintenance_mode ?? false),
                ],
            ],

            // Checkout Page Offer bought by THIS cart's restaurant only
            'checkout_advertisement' => $this->checkoutAdvertisement($cart->restaurant_id),
        ],
    ], 200);
}

private function checkoutAdvertisement($restaurantId): ?array
{
    if (!$restaurantId) {
        return null;
    }

    $advertisements = app(\App\Services\Restaurant\RestaurantAdvertisementService::class);

    $ad = $advertisements->checkoutAdvertisement((int) $restaurantId);

    return $ad ? $advertisements->formatForCustomer($ad) : null;
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

    // Make sure an offer that just started / ended is reflected
    $menuItem = app(\App\Services\Restaurant\RestaurantOfferService::class)
        ->syncMenuItem($menuItem->id);

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

$cart = $this->recalculateCartTotals($cart);

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
                'platform_fee' => $cart->platform_fee,
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

$cart = $this->recalculateCartTotals($cart);

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
            'platform_fee' => $cart->platform_fee,
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

$cart = $this->recalculateCartTotals($cart);

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
            'platform_fee' => $cart->platform_fee,
            'discount' => $cart->discount,
            'tax' => $cart->tax,
            'total_amount' => $cart->total_amount,
        ],
    ],
], 200);
}

public function applyCoupon(Request $request)
{
    $customer = $request->user();

    $validated = $request->validate([
        'coupon_code' => ['required', 'string'],
    ]);

    $cart = \App\Models\CustomerCart::query()
        ->where('customer_id', $customer->id)
        ->first();

    if (!$cart) {
        return response()->json([
            'success' => false,
            'message' => 'Cart is empty.',
        ], 400);
    }

    $coupon = \App\Models\Coupon::query()
        ->where('code', strtoupper(trim($validated['coupon_code'])))
        ->where('is_active', true)
        ->first();

    if (!$coupon) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid or inactive coupon.',
        ], 422);
    }

    // Customer-locked coupon check (e.g. compensation coupon)
    if ($coupon->customer_id !== null && (int) $coupon->customer_id !== (int) $customer->id) {
        return response()->json([
            'success' => false,
            'message' => 'This coupon is not valid for your account.',
        ], 422);
    }

    // Single-use coupon check
    if ($coupon->is_single_use && $coupon->used_at !== null) {
        return response()->json([
            'success' => false,
            'message' => 'This coupon has already been used.',
        ], 422);
    }

    // Check coupon start date
    if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
        return response()->json([
            'success' => false,
            'message' => 'This coupon is not active yet.',
        ], 422);
    }

    // Check coupon expiry
    if ($coupon->expires_at && now()->gt($coupon->expires_at)) {
        return response()->json([
            'success' => false,
            'message' => 'This coupon has expired.',
        ], 422);
    }

    // Check total campaign usage limit
    if ($coupon->total_limit > 0 && $coupon->used_count >= $coupon->total_limit) {
        return response()->json([
            'success' => false,
            'message' => 'This coupon maximum redemption limit has been reached.',
        ], 422);
    }

    // Check per-customer usage limit
    if ($coupon->customer_limit > 0) {
        $customerUsageCount = \App\Models\CouponRedemption::where('coupon_id', $coupon->id)
            ->where('customer_id', $customer->id)
            ->count();
        if ($customerUsageCount >= $coupon->customer_limit) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reached the redemption limit for this coupon.',
            ], 422);
        }
    }

    // Check restaurant applicability
    if ($coupon->applicable_type === 'selected' && !empty($coupon->applicable_restaurants)) {
        $allowed = (array) $coupon->applicable_restaurants;
        $restaurantName = $cart->restaurant?->restaurant_name ?? '';
        $isAllowed = in_array((string)$cart->restaurant_id, $allowed, true) ||
                     in_array($cart->restaurant_id, $allowed, true) ||
                     in_array($restaurantName, $allowed, true);
        if (!$isAllowed) {
            return response()->json([
                'success' => false,
                'message' => 'This coupon is not valid for the selected restaurant.',
            ], 422);
        }
    }

    // Coupon minimum order amount is checked against FOOD SUBTOTAL only
    if ($cart->subtotal < $coupon->min_order_amount) {
        return response()->json([
            'success' => false,
            'message' => 'Minimum order amount for this coupon is ₹'
                . number_format($coupon->min_order_amount, 2),
        ], 422);
    }

    // Calculate discount ONLY on food subtotal
    if ($coupon->discount_type === 'percentage') {

        $discount = round(
            ($cart->subtotal * $coupon->discount_value) / 100,
            2
        );

        // Apply maximum discount limit if configured
        if ($coupon->max_discount_amount !== null) {
            $discount = min(
                $discount,
                $coupon->max_discount_amount
            );
        }

    } else {

        // Fixed discount
        $discount = min(
            $coupon->discount_value,
            $cart->subtotal
        );
    }

    $cart->coupon_code = $coupon->code;
    $cart->discount_type = $coupon->discount_type;
    $cart->discount_value = $coupon->discount_value;
    $cart->discount = $discount;

    /*
    |--------------------------------------------------------------------------
    | GST
    |--------------------------------------------------------------------------
    |
    | Food GST is calculated AFTER discount.
    | Delivery, packing and platform GST remain unchanged.
    |
    */

    $discountedFoodAmount =
        $cart->subtotal - $cart->discount;

    $foodGst = round(
        ($discountedFoodAmount * self::FOOD_GST_RATE) / 100,
        2
    );

    $deliveryGst = round(
        ($cart->delivery_charge * self::SERVICE_GST_RATE) / 100,
        2
    );

    $packingGst = round(
        ($cart->packing_charge * self::SERVICE_GST_RATE) / 100,
        2
    );

    $platformGst = round(
        ($cart->platform_fee * self::SERVICE_GST_RATE) / 100,
        2
    );

    $cart->tax =
        $foodGst
        + $deliveryGst
        + $packingGst
        + $platformGst;

    $cart->total_amount =
        $cart->subtotal
        + $cart->delivery_charge
        + $cart->packing_charge
        + $cart->platform_fee
        + $cart->tax
        - $cart->discount;

    $cart->save();

    return response()->json([
        'success' => true,
        'message' => 'Coupon applied successfully.',
        'data' => [
            'cart' => [
                'id' => $cart->id,
                'subtotal' => $cart->subtotal,
                'delivery_charge' => $cart->delivery_charge,
                'packing_charge' => $cart->packing_charge,
                'platform_fee' => $cart->platform_fee,
                'coupon_code' => $cart->coupon_code,
                'discount_type' => $cart->discount_type,
                'discount_value' => $cart->discount_value,
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

public function removeCoupon(Request $request)
{
    $customer = $request->user();

    $cart = \App\Models\CustomerCart::query()
        ->where('customer_id', $customer->id)
        ->first();

    if (!$cart) {
        return response()->json([
            'success' => false,
            'message' => 'Cart is empty.',
        ], 400);
    }

    $cart->coupon_code = null;
    $cart->discount_type = null;
    $cart->discount_value = 0;
    $cart->discount = 0;

    $this->recalculateCartTotals($cart);
    $cart->save();

    return response()->json([
        'success' => true,
        'message' => 'Coupon removed successfully.',
        'data' => [
            'cart' => [
                'id' => $cart->id,
                'subtotal' => $cart->subtotal,
                'delivery_charge' => $cart->delivery_charge,
                'packing_charge' => $cart->packing_charge,
                'platform_fee' => $cart->platform_fee,
                'coupon_code' => null,
                'discount_type' => null,
                'discount_value' => 0,
                'discount' => 0,
                'tax' => $cart->tax,
                'total_amount' => $cart->total_amount,
            ],
        ],
    ], 200);
}

public function availableCoupons(Request $request)
{
    $customer = $request->user();

    $coupons = \App\Models\Coupon::query()
        ->where('is_active', true)
        ->where(function ($query) use ($customer) {
            $query->whereNull('customer_id')
                ->orWhere('customer_id', $customer->id);
        })
        ->where(function ($query) {
            $query->whereNull('starts_at')
                ->orWhere('starts_at', '<=', now());
        })
        ->where(function ($query) {
            $query->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now());
        })
        ->where(function ($query) {
            $query->where('is_single_use', false)
                ->orWhereNull('used_at');
        })
        ->where(function ($query) {
            $query->where('total_limit', 0)
                ->orWhereRaw('used_count < total_limit');
        })
        ->orderByDesc('is_compensation')
        ->orderByDesc('id')
        ->get();

    $cart = \App\Models\CustomerCart::where('customer_id', $customer->id)->first();
    $subtotal = (float) ($cart?->subtotal ?? 0);

    return response()->json([
        'success' => true,
        'message' => 'Available coupons retrieved successfully.',
        'data' => [
            'applied_coupon' => $cart?->coupon_code,
            'coupons' => $coupons->map(function ($coupon) use ($subtotal) {
                $isApplicable = $subtotal >= (float) $coupon->min_order_amount;

                $estimatedDiscount = 0;
                if ($coupon->discount_type === 'percentage') {
                    $estimatedDiscount = round(($subtotal * (float) $coupon->discount_value) / 100, 2);
                    if ($coupon->max_discount_amount !== null) {
                        $estimatedDiscount = min($estimatedDiscount, (float) $coupon->max_discount_amount);
                    }
                } else {
                    $estimatedDiscount = min((float) $coupon->discount_value, $subtotal);
                }

                $title = $coupon->is_compensation
                    ? 'Compensation Refund Coupon (20%)'
                    : ($coupon->name ?: ($coupon->discount_type === 'percentage'
                        ? "{$coupon->discount_value}% OFF"
                        : '₹' . number_format($coupon->discount_value, 0) . ' OFF'));

                $desc = $coupon->is_compensation
                    ? 'Exclusive single-use compensation coupon of ₹' . number_format($coupon->discount_value, 2) . ' for your previous complaint.'
                    : ($coupon->description ?: ($coupon->min_order_amount > 0
                        ? 'Valid on orders above ₹' . number_format($coupon->min_order_amount, 0)
                        : 'Valid on all orders'));

                return [
                    'id' => $coupon->id,
                    'code' => $coupon->code,
                    'name' => $coupon->name,
                    'title' => $title,
                    'description' => $desc,
                    'discount_type' => $coupon->discount_type,
                    'discount_value' => (float) $coupon->discount_value,
                    'min_order_amount' => (float) $coupon->min_order_amount,
                    'max_discount_amount' => $coupon->max_discount_amount ? (float) $coupon->max_discount_amount : null,
                    'is_compensation' => (bool) $coupon->is_compensation,
                    'is_single_use' => (bool) $coupon->is_single_use,
                    'expires_at' => $coupon->expires_at?->format('d M Y, h:i A'),
                    'is_applicable' => $isApplicable,
                    'estimated_savings' => $estimatedDiscount,
                ];
            })->values(),
        ],
    ], 200);
}

}
