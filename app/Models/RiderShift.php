<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\RiderShiftBooking;

class RiderShift extends Model
{
    protected $fillable = [
        'shift_name',
        'start_time',
        'end_time',
        'is_night',
        'status',
    ];

    protected $casts = [
        'is_night' => 'boolean',
        'status' => 'boolean',
    ];

    public function bookings()
{
    return $this->hasMany(RiderShiftBooking::class, 'shift_id');
}

}