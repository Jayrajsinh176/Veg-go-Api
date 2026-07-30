<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantAddress extends Model
{
    protected $fillable = [
        'restaurant_id',
        'full_address',
        'shop_plot_number',
        'floor',
        'building_name',
        'pincode',
        'complete_address',
        'latitude',
        'longitude',
        'landmark',
        'restaurant_front_image',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}