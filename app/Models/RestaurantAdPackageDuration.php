<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantAdPackageDuration extends Model
{
    protected $fillable = [
        'package_id',
        'duration_days',
        'price',
        'is_active',
    ];

    protected $casts = [
        'duration_days' => 'integer',
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function package()
    {
        return $this->belongsTo(RestaurantAdPackage::class, 'package_id');
    }
}
