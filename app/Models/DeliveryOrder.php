<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\OrderPayment;
use App\Models\OrderItem;

class DeliveryOrder extends Model
{
    protected $fillable = [

        // Order
        'order_number',

        // Restaurant
        'restaurant_name',
        'restaurant_address',
        'restaurant_phone',

        // Customer
        'customer_name',
        'customer_phone',
        'delivery_address',

        // Locations
        'pickup_latitude',
        'pickup_longitude',
        'delivery_latitude',
        'delivery_longitude',

        // Rider
        'rider_earning',
        'rider_id',

        // Order Status
        'status',
        'restaurant_status',

        // Timeline
        'accepted_at',
        'arrived_at',
        'picked_up_at',
        'delivered_at',

     // Verification

        'pickup_otp',
'pickup_verified_at',
'pickup_otp_expires_at',

'delivery_otp_expires_at',
'delivery_otp',
'delivery_verified_at',
    ];

    protected $casts = [

        // Coordinates
        'pickup_latitude' => 'decimal:7',
        'pickup_longitude' => 'decimal:7',
        'delivery_latitude' => 'decimal:7',
        'delivery_longitude' => 'decimal:7',

        // Money
        'rider_earning' => 'decimal:2',

        // Timeline
        'accepted_at' => 'datetime',
        'arrived_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
'pickup_otp_expires_at' => 'datetime',
'delivery_otp_expires_at' => 'datetime',
        'pickup_verified_at' => 'datetime',
'delivery_verified_at' => 'datetime',
    ];

public function rider()
{
    return $this->belongsTo(Rider::class);
}

public function payment()
{
    return $this->hasOne(OrderPayment::class, 'delivery_order_id');
}

public function items()
{
    return $this->hasMany(OrderItem::class, 'delivery_order_id');
}

}