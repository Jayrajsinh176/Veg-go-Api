<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderEarningSlab extends Model
{
    protected $fillable = [
        'min_distance',
        'max_distance',
        'base_amount',
        'status',
    ];

    protected $casts = [
        'min_distance' => 'decimal:2',
        'max_distance' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'status' => 'boolean',
    ];
}