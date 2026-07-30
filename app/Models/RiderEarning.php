<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderEarning extends Model
{
    protected $fillable = [
        'rider_id',
        'delivery_order_id',
        'type',
        'amount',
        'title',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function rider()
    {
        return $this->belongsTo(Rider::class);
    }

    public function deliveryOrder()
    {
        return $this->belongsTo(DeliveryOrder::class);
    }
}