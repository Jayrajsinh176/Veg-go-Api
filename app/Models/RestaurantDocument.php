<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantDocument extends Model
{
protected $fillable = [
    'restaurant_id',
    'outlet_type_id',
    'pan_number',
    'gstin_number',
    'upi_id',
    'upi_holder_name',
    'fssai_number',

    'status',
    'rejection_reason',
    'reviewed_at',
    'reviewed_by',
];

protected $casts = [
    'reviewed_at' => 'datetime',
];

    public function restaurant()
{
    return $this->belongsTo(Restaurant::class);
}

public function outletType()
{
    return $this->belongsTo(RestaurantOutletType::class, 'outlet_type_id');
}

}