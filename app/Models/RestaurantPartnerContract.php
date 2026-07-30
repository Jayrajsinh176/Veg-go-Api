<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantPartnerContract extends Model
{
    protected $fillable = [
        'restaurant_id',
        'accepted',
        'signed_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'accepted' => 'boolean',
        'signed_at' => 'datetime',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}