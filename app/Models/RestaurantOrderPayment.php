<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestaurantOrderPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'payment_method',
        'payment_status',
        'order_amount',
        'delivery_fee',
        'packing_charge',
        'tax',
        'discount',
        'total_amount',
        'transaction_id',
        'payment_gateway',
        'paid_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    /**
     * Restaurant Order
     */
    public function order()
    {
        return $this->belongsTo(RestaurantOrder::class, 'order_id');
    }
}