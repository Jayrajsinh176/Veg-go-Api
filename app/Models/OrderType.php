<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderType extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'minimum_guarantee',
        'image',
        'is_limited_offer',
        'is_active',
    ];

    protected $casts = [
        'minimum_guarantee' => 'decimal:2',
        'is_limited_offer' => 'boolean',
        'is_active' => 'boolean',
    ];
}