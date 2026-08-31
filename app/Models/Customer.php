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
    'name',
    'email',
    'date_of_birth',
    'profile_photo',
    'push_notifications_enabled',
];

    protected $hidden = [
        'remember_token',
    ];

  protected $casts = [
    'phone_verified_at' => 'datetime',
    'push_notifications_enabled' => 'boolean',
];

    public function otps()
    {
        return $this->hasMany(CustomerOtp::class, 'phone', 'phone');
    }

    public function addresses()
{
    return $this->hasMany(CustomerAddress::class);
}

public function favoriteRestaurants()
{
    return $this->hasMany(CustomerFavoriteRestaurant::class);
}

public function favoriteFoods()
{
    return $this->hasMany(CustomerFavoriteFood::class);
}

public function notifications()
{
    return $this->hasMany(CustomerNotification::class);
}

}