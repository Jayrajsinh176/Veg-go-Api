<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'discount_type',
        'discount_value',
        'min_order_amount',
        'max_discount_amount',
        'is_active',
        'starts_at',
        'expires_at',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}