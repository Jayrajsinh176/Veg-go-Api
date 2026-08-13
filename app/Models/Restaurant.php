<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class Restaurant extends Authenticatable
{
    use HasApiTokens, Notifiable;

  protected $fillable = [
    'mobile',
    'owner_name',
    'restaurant_name',
    'email',
    'whatsapp_number',
    'is_whatsapp_same',
    'same_time_all_days',
    'status',
    'is_online',
    'wallet_balance',
    'onboarding_step',
    'is_profile_completed',
];

    protected $hidden = [
        'remember_token',
    ];

protected $casts = [
    'is_whatsapp_same' => 'boolean',
    'same_time_all_days' => 'boolean',
    'is_profile_completed' => 'boolean',
       'wallet_balance' => 'decimal:2',
    'is_online' => 'boolean',
];

public function address()
{
    return $this->hasOne(RestaurantAddress::class);
}

public function workingHours()
{
    return $this->hasMany(RestaurantWorkingHour::class);
}

public function workingDays()
{
    return $this->hasMany(RestaurantWorkingDay::class);
}
public function document()
{
    return $this->hasOne(RestaurantDocument::class);
}

public function categories()
{
    return $this->hasMany(RestaurantCategory::class);
}

public function menuItems()
{
    return $this->hasMany(RestaurantMenuItem::class);
}

public function partnerContract()
{
    return $this->hasOne(RestaurantPartnerContract::class);
}

public function reviews()
{
    return $this->hasMany(RestaurantReview::class);
}

public function orders()
{
    return $this->hasMany(RestaurantOrder::class);
}

public function walletTransactions()
{
    return $this->hasMany(RestaurantWalletTransaction::class);
}

public function settings()
{
    return $this->hasOne(RestaurantSetting::class);
}

}