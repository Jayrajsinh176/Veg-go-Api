<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantWorkingDay extends Model
{
    protected $fillable = [
        'restaurant_id',
        'day',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}