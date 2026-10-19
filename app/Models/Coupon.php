<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'order_id',
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'min_order_amount',
        'max_discount_amount',
        'total_limit',
        'customer_limit',
        'used_count',
        'applicable_type',
        'applicable_restaurants',
        'applicable_categories',
        'is_single_use',
        'is_compensation',
        'is_active',
        'starts_at',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'total_limit' => 'integer',
        'customer_limit' => 'integer',
        'used_count' => 'integer',
        'applicable_restaurants' => 'array',
        'applicable_categories' => 'array',
        'is_single_use' => 'boolean',
        'is_compensation' => 'boolean',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    protected $appends = [
        'computed_status',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrder::class, 'order_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function getComputedStatusAttribute(): string
    {
        if (!$this->is_active) {
            return 'Expired';
        }

        if ($this->expires_at && now()->gt($this->expires_at)) {
            return 'Expired';
        }

        if ($this->total_limit > 0 && $this->used_count >= $this->total_limit) {
            return 'Expired';
        }

        if ($this->starts_at && now()->lt($this->starts_at)) {
            return 'Scheduled';
        }

        return 'Active';
    }
}