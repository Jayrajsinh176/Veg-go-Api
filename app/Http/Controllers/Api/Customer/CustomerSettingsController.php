<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;

class CustomerSettingsController extends Controller
{
    /**
     * Public application configuration for customer and mobile apps.
     */
    public function appConfig()
    {
        $settings = PlatformSetting::getSettings();

        return response()->json([
            'success' => true,
            'message' => 'App configuration fetched successfully.',
            'data' => [
                'platform_name' => $settings->platform_name,
                'support_email' => $settings->support_email,
                'support_phone' => $settings->support_phone,
                'website' => $settings->website,
                'logo_url' => $settings->logo_url,

                'maintenance_mode' => (bool) $settings->maintenance_mode,
                'minimum_order_value' => (float) $settings->minimum_order_value,
                'order_cancellation' => (bool) $settings->order_cancellation,
                'cancellation_window' => (int) $settings->cancellation_window,

                'base_delivery_fee' => (float) $settings->base_delivery_fee,
                'per_km_fee' => (float) $settings->per_km_fee,
                'free_delivery_above' => (float) $settings->free_delivery_above,
                'platform_fee' => (float) $settings->platform_fee,
                'small_order_threshold' => (float) $settings->small_order_threshold,
                'small_order_fee' => (float) $settings->small_order_fee,

                'cod_enabled' => (bool) $settings->cod_enabled,
                'max_cod_order_amount' => (float) $settings->max_cod_order_amount,
                'online_payment_enabled' => (bool) $settings->online_payment_enabled,

                'tax_inclusive' => (bool) $settings->tax_inclusive,
                'gst_rate' => (float) $settings->gst_rate,
                'currency' => 'INR',
                'currency_symbol' => '₹',
                'timezone' => 'Asia/Kolkata',
            ],
        ], 200);
    }

    /**
     * Update customer's push notification setting.
     */
    public function updateNotifications(Request $request)
    {
        $customer = $request->user();

        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $customer->update([
            'push_notifications_enabled' => $validated['enabled'],
        ]);

        return response()->json([
            'success' => true,
            'message' => $validated['enabled']
                ? 'Push notifications enabled successfully.'
                : 'Push notifications disabled successfully.',
            'data' => [
                'push_notifications_enabled' => (bool) $customer->push_notifications_enabled,
            ],
        ], 200);
    }

    /**
     * Authenticated customer settings + platform configuration.
     */
    public function show(Request $request)
    {
        $customer = $request->user();
        $settings = PlatformSetting::getSettings();

        return response()->json([
            'success' => true,
            'message' => 'Settings fetched successfully.',
            'data' => [
                'customer' => [
                    'push_notifications_enabled' => (bool) $customer?->push_notifications_enabled,
                ],
                'platform' => [
                    'platform_name' => $settings->platform_name,
                    'support_email' => $settings->support_email,
                    'support_phone' => $settings->support_phone,
                    'website' => $settings->website,
                    'logo_url' => $settings->logo_url,

                    'maintenance_mode' => (bool) $settings->maintenance_mode,
                    'minimum_order_value' => (float) $settings->minimum_order_value,
                    'order_cancellation' => (bool) $settings->order_cancellation,
                    'cancellation_window' => (int) $settings->cancellation_window,

                    'base_delivery_fee' => (float) $settings->base_delivery_fee,
                    'per_km_fee' => (float) $settings->per_km_fee,
                    'free_delivery_above' => (float) $settings->free_delivery_above,
                    'platform_fee' => (float) $settings->platform_fee,
                    'small_order_threshold' => (float) $settings->small_order_threshold,
                    'small_order_fee' => (float) $settings->small_order_fee,

                    'cod_enabled' => (bool) $settings->cod_enabled,
                    'max_cod_order_amount' => (float) $settings->max_cod_order_amount,
                    'online_payment_enabled' => (bool) $settings->online_payment_enabled,

                    'currency' => 'INR',
                    'currency_symbol' => '₹',
                ],
            ],
        ], 200);
    }
}