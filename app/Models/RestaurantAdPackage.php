<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantAdPackage extends Model
{
    public const HOME_SCREEN_SHOWCASE = 'HOME_SCREEN_SHOWCASE';
    public const FOOD_ITEM_PROMOTION = 'FOOD_ITEM_PROMOTION';
    public const CHECKOUT_PAGE_OFFER = 'CHECKOUT_PAGE_OFFER';
    public const FEATURED_RESTAURANT = 'FEATURED_RESTAURANT';

    protected $fillable = [
        'name',
        'code',
        'description',
        'placement',
        'requires_image',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'requires_image' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function durations()
    {
        return $this->hasMany(RestaurantAdPackageDuration::class, 'package_id');
    }

    public function activeDurations()
    {
        return $this->durations()
            ->where('is_active', true)
            ->orderBy('duration_days');
    }

    public function advertisements()
    {
        return $this->hasMany(RestaurantAdvertisement::class, 'package_id');
    }

    /**
     * target_type stored on the advertisement for this package.
     */
    public function targetType(): string
    {
        return $this->placement;
    }
}
