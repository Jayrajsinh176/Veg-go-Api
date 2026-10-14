<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantAdvertisementHistory extends Model
{
    public const ACTOR_RESTAURANT = 'restaurant';
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_SUPER_ADMIN = 'super_admin';
    public const ACTOR_GATEWAY = 'gateway';
    public const ACTOR_SYSTEM = 'system';

    protected $fillable = [
        'advertisement_id',
        'action',
        'from_status',
        'to_status',
        'payment_status',
        'actor_type',
        'actor_id',
        'actor_name',
        'note',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function advertisement()
    {
        return $this->belongsTo(RestaurantAdvertisement::class, 'advertisement_id');
    }
}
