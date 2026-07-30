<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkZone extends Model
{
    protected $fillable = [
        'city_id',
        'name',
        'minimum_guarantee',
        'weekly_earning_upto',
        'is_best_zone',
        'is_active',
    ];

    protected $casts = [
        'minimum_guarantee' => 'decimal:2',
        'weekly_earning_upto' => 'decimal:2',
        'is_best_zone' => 'boolean',
        'is_active' => 'boolean',
    ];
}