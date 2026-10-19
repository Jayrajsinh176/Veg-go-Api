<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Models\CustomerCart;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantOrderPayment;
use App\Models\RestaurantMenuItem;
use App\Services\Order\OrderCancellationService;
use App\Services\Restaurant\RestaurantOfferService;

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

        $settings = \App\Models\PlatformSetting::getSettings();

        // 1. Platform Maintenance Mode Check
        if ($settings->maintenance_mode) {
            return response()->json([
                'success' => false,
                'message' => 'VegGo is currently undergoing scheduled maintenance. Please check back shortly.',
            ], 503);
        }

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
         * Re-apply offers so the order uses today's price
         * (an offer may have started or ended since items were added).
         * This also re-prices the cart and its totals if anything changed.
         */
        $offerService = app(RestaurantOfferService::class);

        $menuItems = $cart->items
            ->pluck('menu_item_id')
            ->unique()
            ->mapWithKeys(fn ($menuItemId) => [
                $menuItemId => $offerService->syncMenuItem($menuItemId),
            ]);

        $cart->refresh()->load('items');

        // Always rebuild totals so the order (and PayU amount) never uses a stale cart.
        app(CustomerCartController::class)->recalculateCartTotals($cart);
        $cart->save();

        // 2. Minimum Order Value Check
        $minOrderValue = (float) ($settings->minimum_order_value ?? 0.00);
        if ($minOrderValue > 0 && (float) $cart->subtotal < $minOrderValue) {
            return response()->json([
                'success' => false,
                'message' => 'Minimum order value is ₹' . number_format($minOrderValue, 0) . '. Please add more items to proceed.',
            ], 422);
        }

        // 3. Payment Method Validation & Limits
        if ($validated['payment_method'] === 'cash') {
            if (!$settings->cod_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cash on Delivery is currently unavailable on the platform.',
                ], 422);
            }

            // Orders above max_cod_order_amount (default: ₹2,000) cannot use COD
            $maxCodLimit = (float) ($settings->max_cod_order_amount ?? 2000.00);
            if ($maxCodLimit > 0 && (float) $cart->total_amount > $maxCodLimit) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cash on Delivery is only available for orders up to ₹' . number_format($maxCodLimit, 0) . '. Please pay online to place this order.',
                ], 422);
            }
        } elseif ($validated['payment_method'] === 'online') {
            if (!$settings->online_payment_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Online payments are currently unavailable on the platform.',
                ], 422);
            }
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
         * Validate restaurant availability (active status, online status, operating days/hours, delivery range).
         */
        $restaurantCheckError = $this->validateRestaurantAvailability(
            (int) $cart->restaurant_id,
            $address
        );

        if ($restaurantCheckError !== null) {
            return response()->json([
                'success' => false,
                'message' => $restaurantCheckError,
            ], 422);
        }

        /*
         * Start database transaction.
         */
        DB::beginTransaction();

        try {

            /*
             * Generate unique order number (e.g. VG-0910-001).
             */
            $prefix = 'VG-' . now()->format('dm') . '-';

            $lastOrder = RestaurantOrder::where('order_number', 'LIKE', "{$prefix}%")
                ->whereDate('created_at', now()->toDateString())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $sequence = 1;
            if ($lastOrder && preg_match('/' . preg_quote($prefix, '/') . '(\d+)$/', $lastOrder->order_number, $matches)) {
                $sequence = ((int) $matches[1]) + 1;
            }

            do {
                $orderNumber = $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT);
                $sequence++;
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
                'platform_fee' => $cart->platform_fee ?? 0,
                'small_order_fee' => $cart->small_order_fee ?? 0,
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
                $menuItem = $menuItems[$cartItem->menu_item_id] ?? null;

                // Save which offer was used and how much it saved
                $offerId = $menuItem?->active_offer_id;
                $originalPrice = $menuItem ? (float) $menuItem->price : (float) $cartItem->unit_price;
                $offerDiscount = $offerId
                    ? round(max(0, $originalPrice - (float) $cartItem->unit_price) * $cartItem->quantity, 2)
                    : 0;

                RestaurantOrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $cartItem->menu_item_id,
                    'menu_item_name' => $cartItem->menu_item_name,
                    'variant_name' => null,
                    'quantity' => $cartItem->quantity,
                    'original_price' => $originalPrice,
                    'offer_id' => $offerId,
                    'offer_discount' => $offerDiscount,
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

            // If a coupon was applied, mark redemption and increment used_count
            if (!empty($cart->coupon_code)) {
                $appliedCoupon = \App\Models\Coupon::where('code', strtoupper(trim($cart->coupon_code)))->first();
                if ($appliedCoupon) {
                    $appliedCoupon->increment('used_count');
                    if ($appliedCoupon->is_single_use) {
                        $appliedCoupon->update([
                            'used_at'  => now(),
                            'order_id' => $order->id,
                        ]);
                    }
                    \App\Models\CouponRedemption::create([
                        'coupon_id' => $appliedCoupon->id,
                        'customer_id' => $customer->id,
                        'order_id' => $order->id,
                        'discount_amount' => (float) ($cart->discount ?? 0),
                        'order_amount' => (float) ($cart->total_amount ?? 0),
                    ]);
                }
            }

            /*
             * Remove cart after successful order creation.
             */
            $cart->items()->delete();
            $cart->delete();

            DB::commit();

            // Send in-app notification to restaurant & customer for COD orders
            if ($validated['payment_method'] === 'cash') {
                app(\App\Services\Notification\OrderNotificationService::class)->orderPlaced($order);
            }

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
                                'original_price' => $item->original_price,
                                'offer_discount' => $item->offer_discount,
                                'unit_price' => $item->unit_price,
                                'total_price' => $item->total_price,
                            ];
                        })->values(),

                        'subtotal' => $order->subtotal,
                        'delivery_charge' => $order->delivery_charge,
                        'packing_charge' => $order->packing_charge,
                        'platform_fee' => $order->platform_fee,
                        'small_order_fee' => (float) ($order->small_order_fee ?? 0.00),
                        'tax' => $order->tax,
                        'discount' => $order->discount,
                        'total_amount' => $order->total_amount,

                        'payment_method' => $order->payment_method,
                        'payment_status' => $order->payment_status,
                        'order_status' => $order->order_status,
                        'payu_initiate_url' => $order->payment_method === 'online' ? "/api/customer/orders/{$order->id}/payu/initiate" : null,
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
            'review.reply',
            'latestRefund.coupon',
        ])
        ->first();

    if (!$order) {
        return response()->json([
            'success' => false,
            'message' => 'Order not found.',
        ], 404);
    }

    /*
     * Delivery OTP is now generated automatically when the rider
     * verifies pickup (in RiderOrderService::verifyPickupOtp).
     * This block is kept as a safety-net fallback: if for any
     * reason the OTP was not generated at pickup, create one here
     * so the customer is never left without an OTP to share.
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
                        'original_price' => $item->original_price,
                        'offer_discount' => $item->offer_discount,
                        'unit_price' => $item->unit_price,
                        'total_price' => $item->total_price,
                        'special_instruction' => $item->special_instruction,
                    ];
                })->values(),

                'subtotal' => $order->subtotal,
                'delivery_charge' => $order->delivery_charge,
                'packing_charge' => $order->packing_charge,
                'platform_fee' => $order->platform_fee,
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
                // customer | restaurant | admin | system (payment not completed)
                'cancelled_by' => $order->cancelled_by,

                /*
                 * Review & Rating details (makes frontend integration simple)
                 */
                'is_reviewed' => $order->review !== null,
                'can_review'  => $order->order_status === 'delivered' && $order->review === null,
                'review'      => $order->review ? [
                    'id'               => $order->review->id,
                    'rating'           => (int) $order->review->rating,
                    'review'           => $order->review->review,
                    'created_at'       => $order->review->created_at,
                    'restaurant_reply' => $order->review->reply ? [
                        'reply'      => $order->review->reply->reply,
                        'replied_at' => optional($order->review->reply->created_at)->diffForHumans(),
                    ] : null,
                ] : null,

                /*
                 * Complaint & Refund details (20% on food subtotal only)
                 */
                'can_complain'             => $order->order_status === 'delivered' && $order->latestRefund === null,
                'eligible_refund_preview'  => round(((float) $order->subtotal * 20) / 100, 2),
                'complaint'                => $order->latestRefund ? [
                    'id'                => $order->latestRefund->id,
                    'refund_number'     => $order->latestRefund->refund_number,
                    'scenario'          => $order->latestRefund->scenario,
                    'status'            => $order->latestRefund->status,
                    'reason'            => $order->latestRefund->reason,
                    'customer_note'     => $order->latestRefund->customer_note,
                    'proof_image_url'   => $order->latestRefund->proof_image_url,
                    'refund_amount'     => (float) $order->latestRefund->amount,
                    'coupon_code'       => $order->latestRefund->coupon?->code,
                    'rejection_reason'  => $order->latestRefund->rejection_reason,
                    'created_at'        => $order->latestRefund->created_at?->toIso8601String(),
                ] : null,
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
            'review',
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
                            'original_price' => $item->original_price,
                            'offer_discount' => $item->offer_discount,
                            'unit_price' => $item->unit_price,
                            'total_price' => $item->total_price,
                        ];
                    })->values(),

                    'subtotal' => $order->subtotal,
                    'delivery_charge' => $order->delivery_charge,
                    'packing_charge' => $order->packing_charge,
                    'platform_fee' => $order->platform_fee,
                    'tax' => $order->tax,
                    'discount' => $order->discount,
                    'total_amount' => $order->total_amount,

                    'payment_method' => $order->payment_method,
                    'payment_status' => $order->payment_status,

                    'order_status' => $order->order_status,

                    'is_reviewed' => $order->review !== null,
                    'can_review'  => $order->order_status === 'delivered' && $order->review === null,
                    'review'      => $order->review ? [
                        'rating' => (int) $order->review->rating,
                        'review' => $order->review->review,
                    ] : null,

                    'placed_at' => $order->placed_at,
                    'delivered_at' => $order->delivered_at,
                    'cancelled_at' => $order->cancelled_at,
                    'cancelled_by' => $order->cancelled_by,
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
        'restaurant.workingDays',
        'restaurant.workingHours',
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

    $restaurant = $order->restaurant;

    // 1. Validate restaurant availability
    if (!$restaurant || $restaurant->status !== 'approved') {
        return response()->json([
            'success' => false,
            'message' => 'This restaurant is no longer active on VegGo.',
        ], 422);
    }

    if (!$restaurant->is_online) {
        return response()->json([
            'success' => false,
            'message' => 'This restaurant is currently offline and not accepting orders.',
        ], 422);
    }

    // Check operating hours / days if configured
    $todayDay = now()->format('l');
    if ($restaurant->workingDays && $restaurant->workingDays->isNotEmpty()) {
        $isOpenToday = $restaurant->workingDays
            ->pluck('day')
            ->map(fn ($d) => strtolower((string) $d))
            ->contains(strtolower($todayDay));

        if (!$isOpenToday) {
            return response()->json([
                'success' => false,
                'message' => "This restaurant is closed today ({$todayDay}).",
            ], 422);
        }
    }

    if ($restaurant->workingHours && $restaurant->workingHours->isNotEmpty()) {
        $todayHours = $restaurant->workingHours
            ->filter(fn ($h) => strtolower((string) $h->day) === strtolower($todayDay));

        if ($todayHours->isNotEmpty()) {
            $currentTime = now()->format('H:i');
            $isWithinAnySlot = false;

            foreach ($todayHours as $hour) {
                $open = substr((string) $hour->open_time, 0, 5);
                $close = substr((string) $hour->close_time, 0, 5);

                if ($open <= $close) {
                    if ($currentTime >= $open && $currentTime <= $close) {
                        $isWithinAnySlot = true;
                        break;
                    }
                } else {
                    if ($currentTime >= $open || $currentTime <= $close) {
                        $isWithinAnySlot = true;
                        break;
                    }
                }
            }

            if (!$isWithinAnySlot) {
                $slotsFormatted = $todayHours->map(
                    fn ($h) => substr((string) $h->open_time, 0, 5) . ' - ' . substr((string) $h->close_time, 0, 5)
                )->implode(', ');

                return response()->json([
                    'success' => false,
                    'message' => "This restaurant is currently closed. Today's hours: {$slotsFormatted}.",
                ], 422);
            }
        }
    }

    // 2. Check menu items availability & sync latest offers/prices
    $availableItems = [];
    $unavailableItems = [];

    $offerService = app(\App\Services\Restaurant\RestaurantOfferService::class);

    foreach ($order->items as $orderItem) {
        $menuItem = \App\Models\RestaurantMenuItem::query()
            ->where('id', $orderItem->menu_item_id)
            ->where('restaurant_id', $order->restaurant_id)
            ->where('is_active', true)
            ->where('is_available', true)
            ->first();

        if (!$menuItem) {
            $unavailableItems[] = [
                'menu_item_id' => $orderItem->menu_item_id,
                'name'         => $orderItem->menu_item_name,
            ];
            continue;
        }

        // Sync live prices & active offers
        try {
            $menuItem = $offerService->syncMenuItem($menuItem->id);
        } catch (\Throwable $e) {
            // Keep existing menu item if offer sync fails
        }

        $availableItems[] = [
            'menu_item'  => $menuItem,
            'quantity'   => $orderItem->quantity,
            'unit_price' => $menuItem->discount_price !== null ? $menuItem->discount_price : $menuItem->price,
        ];
    }

    // If none of the items are available
    if (empty($availableItems)) {
        return response()->json([
            'success' => false,
            'message' => 'None of the items from this previous order are currently available.',
            'data'    => [
                'unavailable_items' => $unavailableItems,
            ],
        ], 422);
    }

    // 3. Handle existing cart
    $cart = CustomerCart::query()
        ->where('customer_id', $customer->id)
        ->first();

    $replaceCart = $request->boolean('replace_cart') || $request->boolean('clear_cart');

    if ($cart && (int) $cart->restaurant_id !== (int) $order->restaurant_id) {
        if (!$replaceCart) {
            return response()->json([
                'success' => false,
                'message' => 'Your cart contains items from another restaurant. Send replace_cart: true to clear and reorder.',
                'data'    => [
                    'cart_restaurant_id'      => $cart->restaurant_id,
                    'requested_restaurant_id' => $order->restaurant_id,
                    'requires_cart_clear'     => true,
                ],
            ], 409);
        }

        // Replace cart: delete old cart items
        $cart->items()->delete();
        $cart->delete();
        $cart = null;
    }

    // If customer already has a cart for this restaurant and replace_cart is true, clear old items to fresh reorder
    if ($cart && $replaceCart) {
        $cart->items()->delete();
    }

    // Create cart if not exists
    if (!$cart) {
        $cart = CustomerCart::create([
            'customer_id'   => $customer->id,
            'restaurant_id' => $order->restaurant_id,
        ]);
    }

    // 4. Add available items to cart
    foreach ($availableItems as $itemData) {
        $menuItem = $itemData['menu_item'];
        $qty = $itemData['quantity'];
        $unitPrice = $itemData['unit_price'];

        $cartItem = $cart->items()
            ->where('menu_item_id', $menuItem->id)
            ->first();

        if ($cartItem) {
            // If already in cart, update quantity and price
            $cartItem->quantity = $replaceCart ? $qty : ($cartItem->quantity + $qty);
            $cartItem->unit_price = $unitPrice;
            $cartItem->total_price = $cartItem->quantity * $unitPrice;
            $cartItem->save();
        } else {
            $cart->items()->create([
                'menu_item_id'        => $menuItem->id,
                'menu_item_name'      => $menuItem->name,
                'quantity'            => $qty,
                'unit_price'          => $unitPrice,
                'total_price'         => $qty * $unitPrice,
                'special_instruction' => null,
            ]);
        }
    }

    // 5. Recalculate cart totals
    app(CustomerCartController::class)->recalculateCartTotals($cart);
    $cart->save();

    // 6. Response
    $cart->load([
        'restaurant',
        'items.menuItem',
    ]);

    $message = count($unavailableItems) > 0
        ? 'Available items added to cart. ' . count($unavailableItems) . ' item(s) were out of stock.'
        : 'Order items added to cart successfully.';

    return response()->json([
        'success' => true,
        'message' => $message,
        'data'    => [
            'cart' => [
                'id'              => $cart->id,
                'restaurant'      => [
                    'id'   => $cart->restaurant?->id,
                    'name' => $cart->restaurant?->restaurant_name,
                ],
                'items'           => $cart->items->map(function ($item) {
                    return [
                        'id'                  => $item->id,
                        'menu_item_id'        => $item->menu_item_id,
                        'name'                => $item->menu_item_name,
                        'quantity'            => $item->quantity,
                        'unit_price'          => $item->unit_price,
                        'total_price'         => $item->total_price,
                        'special_instruction' => $item->special_instruction,
                        'image'               => $item->menuItem?->image,
                    ];
                })->values(),
                'subtotal'        => $cart->subtotal,
                'delivery_charge' => $cart->delivery_charge,
                'packing_charge'  => $cart->packing_charge,
                'platform_fee'    => $cart->platform_fee,
                'discount'        => $cart->discount,
                'tax'             => $cart->tax,
                'total_amount'    => $cart->total_amount,
            ],
            'unavailable_items' => $unavailableItems,
        ],
    ], 200);
}

    public function cancel(Request $request, $id)
    {
        $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $customer = $request->user();

        $order = RestaurantOrder::query()
            ->where('id', $id)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $reason = $request->filled('reason')
            ? $request->string('reason')->toString()
            : 'Cancelled by customer';

        // Re-checks the status under a row lock, so a restaurant accept can't slip in between
        $cancelled = app(OrderCancellationService::class)->cancel(
            $order,
            OrderCancellationService::BY_CUSTOMER,
            $reason
        );

        if (!$cancelled) {
            return response()->json([
                'success' => false,
                'message' => $order->order_status === 'cancelled'
                    ? 'This order is already cancelled.'
                    : 'Order has already been ' . str_replace('_', ' ', $order->order_status) . ' and cannot be cancelled directly. Please contact support.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully.',
            'data' => [
                'order_id'       => $order->id,
                'order_number'   => $order->order_number,
                'order_status'   => $order->order_status,
                'cancel_reason'  => $order->cancel_reason,
                'cancelled_by'   => $order->cancelled_by,
                'cancelled_at'   => $order->cancelled_at,
            ],
        ], 200);
    }

    /**
     * Validate restaurant status, online availability, working hours/days, and delivery radius.
     */
    protected function validateRestaurantAvailability(int $restaurantId, CustomerAddress $deliveryAddress): ?string
    {
        $restaurant = \App\Models\Restaurant::with(['workingDays', 'workingHours', 'address'])
            ->find($restaurantId);

        if (!$restaurant) {
            return 'Restaurant not found.';
        }

        if ($restaurant->status !== 'approved') {
            return 'This restaurant is currently not active on VegGo.';
        }

        if (!$restaurant->is_online) {
            return 'This restaurant is currently offline and not accepting orders.';
        }

        $todayDay = now()->format('l'); // 'Monday', 'Tuesday', etc.

        // 1. Check working days (if restaurant configured working days)
        if ($restaurant->workingDays && $restaurant->workingDays->isNotEmpty()) {
            $isOpenToday = $restaurant->workingDays
                ->pluck('day')
                ->map(fn ($d) => strtolower((string) $d))
                ->contains(strtolower($todayDay));

            if (!$isOpenToday) {
                return "This restaurant is closed today ({$todayDay}).";
            }
        }

        // 2. Check working hours for today (if configured)
        if ($restaurant->workingHours && $restaurant->workingHours->isNotEmpty()) {
            $todayHours = $restaurant->workingHours
                ->filter(fn ($h) => strtolower((string) $h->day) === strtolower($todayDay));

            if ($todayHours->isNotEmpty()) {
                $currentTime = now()->format('H:i');
                $isWithinAnySlot = false;

                foreach ($todayHours as $hour) {
                    $open = substr((string) $hour->open_time, 0, 5);
                    $close = substr((string) $hour->close_time, 0, 5);

                    if ($open <= $close) {
                        // Normal daytime slot, e.g. 10:00 - 22:30
                        if ($currentTime >= $open && $currentTime <= $close) {
                            $isWithinAnySlot = true;
                            break;
                        }
                    } else {
                        // Overnight slot, e.g. 18:00 - 02:00
                        if ($currentTime >= $open || $currentTime <= $close) {
                            $isWithinAnySlot = true;
                            break;
                        }
                    }
                }

                if (!$isWithinAnySlot) {
                    $slotsFormatted = $todayHours->map(
                        fn ($h) => substr((string) $h->open_time, 0, 5) . ' - ' . substr((string) $h->close_time, 0, 5)
                    )->implode(', ');

                    return "This restaurant is currently closed. Today's operating hours: {$slotsFormatted}.";
                }
            }
        }

        // 3. Check delivery distance against the platform max delivery radius if coordinates are available
        $restaurantAddress = $restaurant->address;

        if (
            $restaurantAddress &&
            $restaurantAddress->latitude !== null &&
            $restaurantAddress->longitude !== null &&
            $deliveryAddress->latitude !== null &&
            $deliveryAddress->longitude !== null
        ) {
            $distance = $this->calculateDistance(
                (float) $restaurantAddress->latitude,
                (float) $restaurantAddress->longitude,
                (float) $deliveryAddress->latitude,
                (float) $deliveryAddress->longitude
            );

            $maxRadius = (float) \App\Models\PlatformSetting::getSettings()->max_delivery_radius;

            if ($distance !== null && $maxRadius > 0 && $distance > $maxRadius) {
                return 'Delivery address is outside this restaurant\'s maximum delivery radius (' . rtrim(rtrim(number_format($maxRadius, 1), '0'), '.') . ' km). Distance: ' . number_format($distance, 1) . ' km.';
            }
        }

        return null;
    }

    /**
     * Haversine formula to calculate distance between two coordinates in kilometers.
     */
    protected function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): ?float
    {
        $earthRadius = 6371;

        $latDiff = deg2rad($lat2 - $lat1);
        $lonDiff = deg2rad($lon2 - $lon1);

        $a = sin($latDiff / 2) * sin($latDiff / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lonDiff / 2) * sin($lonDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

}