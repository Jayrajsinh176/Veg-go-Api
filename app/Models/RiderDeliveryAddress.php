<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderDeliveryAddress extends Model
{
    protected $fillable = [
        'rider_id',
        'house_flat',
        'locality',
        'landmark',
        'pincode',
        'city',
        'state',
    ];
}