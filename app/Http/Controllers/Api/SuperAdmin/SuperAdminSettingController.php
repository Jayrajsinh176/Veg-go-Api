<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SuperAdminSettingController extends Controller
{
    /**
     * Get platform settings.
     */
    public function show()
    {
        $settings = PlatformSetting::getSettings();

        return response()->json([
            'message' => 'Platform settings fetched successfully.',
            'data' => $settings,
        ]);
    }

    /**
     * Update platform settings.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'platform_name' => 'required|string|max:100',
            'support_email' => 'required|email|max:150',
            'support_phone' => 'nullable|string|max:30',
            'website' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'gstin' => 'nullable|string|max:50',
            'cin' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',

            'maintenance_mode' => 'required|boolean',
            'auto_accept_orders' => 'required|boolean',
            'order_cancellation' => 'required|boolean',
            'cancellation_window' => 'required|integer|min:0|max:120',
            'minimum_order_value' => 'required|numeric|min:0',
            'max_orders_per_restaurant' => 'required|integer|min:1',
            'order_id_prefix' => 'required|string|max:20',

            'base_delivery_fee' => 'required|numeric|min:0',
            'per_km_fee' => 'required|numeric|min:0',
            'free_delivery_above' => 'required|numeric|min:0',
            'max_delivery_radius' => 'required|numeric|min:1',
            'platform_fee' => 'required|numeric|min:0',
            'small_order_threshold' => 'required|numeric|min:0',
            'small_order_fee' => 'required|numeric|min:0',
            'restaurant_commission' => 'required|numeric|min:0|max:100',

            'cod_enabled' => 'required|boolean',
            'max_cod_order_amount' => 'required|numeric|min:0',
            'online_payment_enabled' => 'required|boolean',
            'tax_inclusive' => 'required|boolean',
            'gst_rate' => 'required|numeric|min:0|max:100',
            'invoice_prefix' => 'required|string|max:20',
            'hsn_code' => 'nullable|string|max:30',
        ]);

        $setting = PlatformSetting::firstOrCreate(['id' => 1]);
        $setting->update($validated);

        PlatformSetting::clearCache();

        return response()->json([
            'message' => 'Platform settings updated successfully.',
            'data' => $setting->fresh(),
        ]);
    }

    /**
     * Upload platform branding logo.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,webp,svg|max:2048',
        ]);

        $setting = PlatformSetting::firstOrCreate(['id' => 1]);

        if ($setting->logo_path && Storage::disk('public')->exists($setting->logo_path)) {
            Storage::disk('public')->delete($setting->logo_path);
        }

        $path = $request->file('logo')->store('branding', 'public');

        $setting->update(['logo_path' => $path]);
        PlatformSetting::clearCache();

        return response()->json([
            'message' => 'Platform logo updated successfully.',
            'data' => [
                'logo_path' => $path,
                'logo_url' => $setting->fresh()->logo_url,
            ],
        ]);
    }

    /**
     * Remove platform logo.
     */
    public function removeLogo()
    {
        $setting = PlatformSetting::firstOrCreate(['id' => 1]);

        if ($setting->logo_path && Storage::disk('public')->exists($setting->logo_path)) {
            Storage::disk('public')->delete($setting->logo_path);
        }

        $setting->update(['logo_path' => null]);
        PlatformSetting::clearCache();

        return response()->json([
            'message' => 'Platform logo removed successfully.',
            'data' => [
                'logo_path' => null,
                'logo_url' => null,
            ],
        ]);
    }
}

