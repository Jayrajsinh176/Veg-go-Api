<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerOtp extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'otp',
        'expires_at',
        'verified_at',
    ];

    protected $hidden = [
        'otp',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'phone', 'phone');
    }
}