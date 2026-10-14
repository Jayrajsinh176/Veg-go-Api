<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantApplicationNote extends Model
{
    protected $fillable = [
        'restaurant_id',
        'admin_id',
        'admin_name',
        'type',
        'message',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}
