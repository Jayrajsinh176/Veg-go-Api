<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'is_available',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_available' => 'boolean',
    ];

    public function areas()
{
    return $this->hasMany(CityArea::class);
}

}