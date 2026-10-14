<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantMenuItem extends Model
{
    protected $fillable = [
        'restaurant_id',
        'category_id',
        'name',
        'description',
        'price',
        'discount_price',
        'active_offer_id',
        'regular_discount_price',
        'offer_label',
        'image',
        'is_available',
        'is_recommended',
        'is_active',
    ];

    /**
     * Restaurant
     */
    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Category
     */
    public function category()
    {
        return $this->belongsTo(RestaurantCategory::class, 'category_id');
    }

    /**
     * Admin offer currently applied to discount_price
     */
    public function activeOffer()
    {
        return $this->belongsTo(RestaurantOffer::class, 'active_offer_id');
    }

    /**
     * Restaurant's own discount price (without any admin offer).
     * Use this in restaurant/admin menu edit forms.
     */
    public function ownDiscountPrice()
    {
        return $this->active_offer_id
            ? $this->regular_discount_price
            : $this->discount_price;
    }

    /**
     * Set the restaurant's own discount price without overwriting
     * a running offer price.
     */
    public function setOwnDiscountPrice($value): void
    {
        if ($this->active_offer_id) {
            $this->regular_discount_price = $value;
        } else {
            $this->discount_price = $value;
        }
    }

    /**
     * Price the customer pays right now.
     */
    public function sellingPrice(): float
    {
        return (float) ($this->discount_price ?? $this->price);
    }
}
