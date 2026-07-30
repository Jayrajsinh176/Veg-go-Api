<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderPayment extends Model
{
    protected $fillable = [
        'delivery_order_id',
        'payment_method',
        'payment_status',
        'order_amount',
        'delivery_fee',
        'cod_amount',
        'transaction_id',
        'payment_gateway',
        'paid_at',
    ];

    protected $casts = [
        'order_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'cod_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(DeliveryOrder::class, 'delivery_order_id');
    }
}