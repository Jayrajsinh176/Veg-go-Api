<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantSetting extends Model
{
    protected $fillable = [
        'restaurant_id',
        'order_alert_sound',
        'vibration',
        'auto_accept_orders',
        'default_prep_time',
    ];

    protected $casts = [
        'order_alert_sound' => 'boolean',
        'vibration' => 'boolean',
        'auto_accept_orders' => 'boolean',
        'default_prep_time' => 'integer',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}