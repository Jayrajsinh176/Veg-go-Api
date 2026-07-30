<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryKit extends Model
{
    protected $fillable = [
        'name',
        'code',
        'half_tshirt_quantity',
        'full_tshirt_quantity',
        'bag_quantity',
        'image',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}