<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderOtp extends Model
{
    protected $fillable = [
        'phone',
        'otp',
        'expires_at',
        'verified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];
}