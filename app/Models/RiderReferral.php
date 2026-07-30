<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderReferral extends Model
{
    protected $fillable = [
        'referrer_rider_id',
        'referred_rider_id',
        'referral_code',
        'completed_orders',
        'reward_credited',
        'rewarded_at',
    ];

    protected $casts = [
        'reward_credited' => 'boolean',
        'rewarded_at' => 'datetime',
    ];

    public function referrer()
    {
        return $this->belongsTo(Rider::class, 'referrer_rider_id');
    }

    public function referred()
    {
        return $this->belongsTo(Rider::class, 'referred_rider_id');
    }
}