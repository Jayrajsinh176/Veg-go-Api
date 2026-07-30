<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderShiftBooking extends Model
{
    protected $fillable = [
        'rider_id',
        'shift_id',
        'booking_date',
        'status',
        'check_in_time',
        'check_out_time',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
    ];

    public function rider()
    {
        return $this->belongsTo(Rider::class);
    }

    public function shift()
    {
        return $this->belongsTo(RiderShift::class, 'shift_id');
    }
}