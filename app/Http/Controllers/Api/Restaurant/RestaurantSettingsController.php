<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RestaurantSettingsController extends Controller
{
    /**
     * Get Order Management Settings
     */
    public function orderManagement(Request $request)
    {
        $restaurant = $request->user();

        $settings = RestaurantSetting::firstOrCreate(
            ['restaurant_id' => $restaurant->id],
            [
                'order_alert_sound' => true,
                'vibration' => true,
                'auto_accept_orders' => false,
                'default_prep_time' => 30,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Order management settings fetched successfully.',
            'data' => [
                'order_alert_sound' => (bool) $settings->order_alert_sound,
                'vibration' => (bool) $settings->vibration,
                'auto_accept_orders' => (bool) $settings->auto_accept_orders,
                'default_prep_time' => (int) $settings->default_prep_time,
            ],
        ]);
    }

    /**
     * Update Order Management Settings
     */
    public function updateOrderManagement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_alert_sound' => 'sometimes|boolean',
            'vibration' => 'sometimes|boolean',
            'auto_accept_orders' => 'sometimes|boolean',
            'default_prep_time' => 'sometimes|integer|in:15,20,25,30,45,60',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $restaurant = $request->user();

        $settings = RestaurantSetting::firstOrCreate(
            ['restaurant_id' => $restaurant->id],
            [
                'order_alert_sound' => true,
                'vibration' => true,
                'auto_accept_orders' => false,
                'default_prep_time' => 30,
            ]
        );

        $settings->update($request->only([
            'order_alert_sound',
            'vibration',
            'auto_accept_orders',
            'default_prep_time',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Order management settings updated successfully.',
            'data' => [
                'order_alert_sound' => (bool) $settings->order_alert_sound,
                'vibration' => (bool) $settings->vibration,
                'auto_accept_orders' => (bool) $settings->auto_accept_orders,
                'default_prep_time' => (int) $settings->default_prep_time,
            ],
        ]);
    }

    /**
 * Get Restaurant Online Status
 */
public function onlineStatus(Request $request)
{
    $restaurant = $request->user();

    return response()->json([
        'success' => true,
        'message' => 'Restaurant status fetched successfully.',
        'data' => [
            'is_online' => (bool) $restaurant->is_online,
        ],
    ]);
}

/**
 * Temporarily Close / Reopen Restaurant
 */
public function toggleOnlineStatus(Request $request)
{
    $restaurant = $request->user();

    // If restaurant is currently online and has active orders,
    // do not allow restaurant to go offline.
    if ($restaurant->is_online) {

        $hasActiveOrders = $restaurant->orders()
            ->whereIn('order_status', [
                'pending',
                'accepted',
                'preparing',
                'ready_for_pickup',
            ])
            ->exists();

        if ($hasActiveOrders) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot go offline while there are active orders.',
            ], 422);
        }
    }

    $restaurant->update([
        'is_online' => !$restaurant->is_online,
    ]);

    return response()->json([
        'success' => true,
        'message' => $restaurant->is_online
            ? 'Restaurant is now online.'
            : 'Restaurant is temporarily closed.',
        'data' => [
            'is_online' => (bool) $restaurant->is_online,
        ],
    ]);
}

/**
 * Delete Restaurant Account
 */
public function deleteAccount(Request $request)
{
    $restaurant = $request->user();

    // Do not allow account deletion while there are active orders
    $hasActiveOrders = $restaurant->orders()
        ->whereIn('order_status', [
            'pending',
            'accepted',
            'preparing',
            'ready_for_pickup',
        ])
        ->exists();

    if ($hasActiveOrders) {
        return response()->json([
            'success' => false,
            'message' => 'You cannot delete your account while there are active orders.',
        ], 422);
    }

    // Deactivate restaurant instead of permanently deleting database record
    $restaurant->update([
        'status' => 'inactive',
        'is_online' => false,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Restaurant account deleted successfully.',
    ]);
}

}