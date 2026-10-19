<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class PlatformSetting extends Model
{
    use HasFactory;

    protected $table = 'platform_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'maintenance_mode' => 'boolean',
        'auto_accept_orders' => 'boolean',
        'order_cancellation' => 'boolean',
        'cancellation_window' => 'integer',
        'minimum_order_value' => 'float',
        'max_orders_per_restaurant' => 'integer',
        'base_delivery_fee' => 'float',
        'per_km_fee' => 'float',
        'free_delivery_above' => 'float',
        'max_delivery_radius' => 'float',
        'platform_fee' => 'float',
        'restaurant_commission' => 'float',
        'cod_enabled' => 'boolean',
        'max_cod_order_amount' => 'float',
        'small_order_threshold' => 'float',
        'small_order_fee' => 'float',
        'online_payment_enabled' => 'boolean',
        'tax_inclusive' => 'boolean',
        'gst_rate' => 'float',
    ];

    protected $appends = [
        'logo_url',
    ];

    /**
     * Get the full public URL of the platform logo.
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo_path) {
            return null;
        }

        if (str_starts_with($this->logo_path, 'http://') || str_starts_with($this->logo_path, 'https://')) {
            return $this->logo_path;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    /**
     * Retrieve the cached settings instance or fetch from DB.
     */
    public static function getSettings(): self
    {
        return Cache::rememberForever('platform_settings', function () {
            return self::firstOrCreate(
                ['id' => 1],
                [
                    'platform_name' => 'VegGo',
                    'support_email' => 'support@veggo.com',
                    'support_phone' => '+91 98765 43210',
                    'website' => 'https://veggo.com',
                    'company_name' => 'VegGo Technologies Pvt. Ltd.',
                    'legal_name' => 'VegGo Technologies Private Limited',
                    'gstin' => '24ABCDE1234F1Z5',
                    'cin' => 'U72900GJ2026PTC123456',
                    'address' => 'Bharuch, Gujarat, India',
                    'maintenance_mode' => false,
                    'auto_accept_orders' => false,
                    'order_cancellation' => true,
                    'cancellation_window' => 5,
                    'minimum_order_value' => 99.00,
                    'max_orders_per_restaurant' => 100,
                    'order_id_prefix' => 'VZG',
                    'base_delivery_fee' => 25.00,
                    'per_km_fee' => 7.00,
                    'free_delivery_above' => 499.00,
                    'max_delivery_radius' => 15.00,
                    'platform_fee' => 5.00,
                    'small_order_threshold' => 250.00,
                    'small_order_fee' => 10.00,
                    'restaurant_commission' => 30.00,
                    'cod_enabled' => true,
                    'max_cod_order_amount' => 2000.00,
                    'online_payment_enabled' => true,
                    'tax_inclusive' => true,
                    'gst_rate' => 5.00,
                    'invoice_prefix' => 'VZG-INV',
                    'hsn_code' => '996331',
                ]
            );
        });
    }

    /**
     * Clear cached settings.
     */
    public static function clearCache(): void
    {
        Cache::forget('platform_settings');
    }
}

