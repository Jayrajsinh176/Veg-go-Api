<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\City;
use App\Models\WorkZone;
use App\Models\RiderKyc;
use App\Models\RiderReferral;
use App\Models\RiderShiftBooking;

class Rider extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'phone_verified_at',
        'language',
        'city_id',
          'city_area_id',
        'status',
        'onboarding_completed',
        'work_settings_completed',
        'personal_details_completed',
        'profile_completed',
        'delivery_kit_completed',
        'vehicle_type_id',
        'vehicle_number',
        'vehicle_type_selected_at',
        'first_name',
        'last_name',
        'gender',
        'work_zone_id',
        'order_type_id',
        'delivery_kit_id',
        'delivery_bag_amount',
'delivery_bag_payment_status',
'delivery_bag_paid_at',
        'tshirt_size',
        'is_online',
'wallet_balance',
'pending_cod',
'last_online_at',
'latitude',
'longitude',
'heading',
'speed',
'last_location_at',
'referral_code',
    ];

    protected $casts = [
        'phone_verified_at' => 'datetime',
        'onboarding_completed' => 'boolean',
        'work_settings_completed' => 'boolean',
        'personal_details_completed' => 'boolean',
        'profile_completed' => 'boolean',
        'delivery_kit_completed' => 'boolean',
        'vehicle_type_selected_at' => 'datetime',
        'is_online' => 'boolean',
'wallet_balance' => 'decimal:2',
'pending_cod' => 'decimal:2',
'last_online_at' => 'datetime',
'latitude' => 'decimal:7',
'longitude' => 'decimal:7',
'heading' => 'decimal:2',
'speed' => 'decimal:2',
'last_location_at' => 'datetime',
    ];

    public function vehicleType()
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function bankDetail()
{
    return $this->hasOne(RiderBankDetail::class);
}

public function upiDetail()
{
    return $this->hasOne(RiderUpiDetail::class);
}

public function city()
{
    return $this->belongsTo(City::class);
}

public function cityArea()
{
    return $this->belongsTo(CityArea::class);
}

public function workZone()
{
    return $this->belongsTo(WorkZone::class);
}

public function kyc()
{
    return $this->hasOne(RiderKyc::class);
}

public function notifications()
{
    return $this->hasMany(RiderNotification::class);
}

public function referralsMade()
{
    return $this->hasMany(RiderReferral::class, 'referrer_rider_id');
}

public function referredBy()
{
    return $this->hasOne(RiderReferral::class, 'referred_rider_id');
}

public function shiftBookings()
{
    return $this->hasMany(RiderShiftBooking::class);
}

public function earnings()
{
    return $this->hasMany(RiderEarning::class);
}

public function deliveryOrders()
{
    return $this->hasMany(DeliveryOrder::class);
}

public function deliveryAddress()
{
    return $this->hasOne(RiderDeliveryAddress::class);
}

public function applicationLogs()
{
    return $this->hasMany(RiderApplicationLog::class);
}

public function weeklySettlements()
{
    return $this->hasMany(RiderWeeklySettlement::class);
}

public function walletTransactions()
{
    return $this->hasMany(RiderWalletTransaction::class);
}

public function codSettlements()
{
    return $this->hasMany(RiderCodSettlement::class);
}

}