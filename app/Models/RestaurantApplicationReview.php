<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantApplicationReview extends Model
{
    protected $fillable = [
        'restaurant_id',
        'assigned_admin_id',
        'verified_documents',
        'info_requested_at',
        'restaurant_setup_verified_at',
        'menu_setup_verified_at',
        'live_verified_at',
    ];

    protected $casts = [
        'verified_documents' => 'array',
        'info_requested_at' => 'datetime',
        'restaurant_setup_verified_at' => 'datetime',
        'menu_setup_verified_at' => 'datetime',
        'live_verified_at' => 'datetime',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function assignedAdmin()
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }
}
