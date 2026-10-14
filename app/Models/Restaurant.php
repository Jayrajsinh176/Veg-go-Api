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
    'status_before_suspension',
    'suspended_at',
    'is_online',
    'wallet_balance',
    'commission_percentage',
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
    'commission_percentage' => 'decimal:2',
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

public function weeklySettlements()
{
    return $this->hasMany(RestaurantWeeklySettlement::class);
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

public function advertisements()
{
    return $this->hasMany(RestaurantAdvertisement::class);
}

public function supportTickets()
{
    return $this->hasMany(RestaurantSupportTicket::class);
}

public function applicationReview()
{
    return $this->hasOne(RestaurantApplicationReview::class);
}

public function applicationNotes()
{
    return $this->hasMany(RestaurantApplicationNote::class);
}

/**
 * Commission % VegGo takes on this restaurant's orders.
 * Uses the admin-set rate, or the default from config (30%) when none is set.
 */
    public function commissionRate(): float
    {
        if ($this->commission_percentage !== null) {
            return (float) $this->commission_percentage;
        }

        try {
            return (float) (PlatformSetting::getSettings()->restaurant_commission ?? config('restaurant.commission_percentage', 30.00));
        } catch (\Throwable $e) {
            return (float) config('restaurant.commission_percentage', 30.00);
        }
    }

}