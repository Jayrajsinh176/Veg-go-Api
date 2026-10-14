<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerCart extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'restaurant_id',
        'subtotal',
        'delivery_charge',
        'packing_charge',
        'platform_fee',
        'small_order_fee',
        'discount',
        'coupon_code',
        'discount_type',
        'discount_value',
        'tax',
        'total_amount',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'delivery_charge' => 'decimal:2',
        'packing_charge' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'small_order_fee' => 'decimal:2',
        'discount' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'tax' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    /**
     * Customer
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Restaurant
     */
    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Cart Items
     */
    public function items()
    {
        return $this->hasMany(CustomerCartItem::class, 'cart_id');
    }
}