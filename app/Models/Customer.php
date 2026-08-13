<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'phone',
        'phone_verified_at',
        'name',
        'email',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected $casts = [
        'phone_verified_at' => 'datetime',
    ];

    public function otps()
    {
        return $this->hasMany(CustomerOtp::class, 'phone', 'phone');
    }

    public function addresses()
{
    return $this->hasMany(CustomerAddress::class);
}

}