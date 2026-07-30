<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'delivery_order_id',
        'product_name',
        'quantity',
        'price',
        'special_instruction',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(DeliveryOrder::class, 'delivery_order_id');
    }
}