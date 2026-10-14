<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantOffer extends Model
{
    protected $fillable = [
        'restaurant_id',
        'menu_item_id',
        'discount_type',
        'discount_value',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function menuItem()
    {
        return $this->belongsTo(RestaurantMenuItem::class, 'menu_item_id');
    }

    /**
     * Offers that are switched on and running today.
     */
    public function scopeRunning($query)
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today);
    }

    /**
     * inactive | scheduled | expired | active
     */
    public function getStatusAttribute(): string
    {
        if (!$this->is_active) {
            return 'inactive';
        }

        $today = now()->startOfDay();

        if ($this->start_date->gt($today)) {
            return 'scheduled';
        }

        if ($this->end_date->lt($today)) {
            return 'expired';
        }

        return 'active';
    }

    /**
     * Discount amount on the given price.
     */
    public function discountFor(float $price): float
    {
        $discount = $this->discount_type === 'percentage'
            ? $price * (float) $this->discount_value / 100
            : (float) $this->discount_value;

        return round(min($price, max(0, $discount)), 2);
    }

    /**
     * "20% OFF" / "₹30 OFF"
     */
    public function label(): string
    {
        $value = rtrim(rtrim(number_format((float) $this->discount_value, 2, '.', ''), '0'), '.');

        return $this->discount_type === 'percentage'
            ? "{$value}% OFF"
            : "₹{$value} OFF";
    }
}
