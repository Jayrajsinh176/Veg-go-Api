<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantWorkingHour extends Model
{
    protected $fillable = [
        'restaurant_id',
        'day',
        'slot',
        'open_time',
        'close_time',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}