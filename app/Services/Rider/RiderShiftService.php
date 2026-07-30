<?php

namespace App\Services\Rider;

use App\Models\Rider;
use App\Models\RiderShift;
use App\Models\RiderShiftBooking;
use Carbon\Carbon;

class RiderShiftService
{
    /**
     * Get all active shifts.
     */
    public function getAvailableShifts()
    {
        return RiderShift::where('status', true)
            ->orderBy('start_time')
            ->get();
    }


    /**
     * Get current active shift booking.
     */
    public function getCurrentShift(int $riderId)
    {
        return RiderShiftBooking::with('shift')
            ->where('rider_id', $riderId)
            ->where('status', 'active')
            ->whereDate('booking_date', today())
            ->first();
    }

/**
 * Start current shift.
 */
public function startShift(int $riderId)
{
    // Check if rider already has an active shift
    $activeShift = RiderShiftBooking::where('rider_id', $riderId)
        ->where('status', 'active')
        ->first();

    if ($activeShift) {
        return [
            'success' => false,
            'message' => 'You already have an active shift.',
        ];
    }

    $now = now()->format('H:i:s');

$shift = RiderShift::where('status', true)
    ->get()
    ->first(function ($shift) use ($now) {

        $start = Carbon::parse($shift->start_time)->format('H:i:s');
        $end   = Carbon::parse($shift->end_time)->format('H:i:s');

        if ($start > $end) {
            return $now >= $start || $now <= $end;
        }

        return $now >= $start && $now <= $end;
    });



    if (!$shift) {
        return [
            'success' => false,
            'message' => 'No active shift available at this time.',
        ];
    }

 

    // Create shift history
    $booking = RiderShiftBooking::create([
        'rider_id'      => $riderId,
        'shift_id'      => $shift->id,
        'booking_date'  => today(),
        'status'        => 'active',
        'check_in_time' => now(),
    ]);

    // Put rider online
    Rider::where('id', $riderId)->update([
        'is_online'      => true,
        'last_online_at' => now(),
    ]);

    return [
        'success' => true,
        'message' => 'Shift started successfully.',
        'booking' => $booking->fresh('shift'),
    ];
}

/**
 * Complete the current active shift.
 */
public function completeShift(int $riderId)
{
    $booking = RiderShiftBooking::with('shift')
        ->where('rider_id', $riderId)
        ->where('status', 'active')
        ->latest()
        ->first();

    if (!$booking) {
        return [
            'success' => false,
            'message' => 'No active shift found.',
        ];
    }

    $booking->update([
        'status' => 'completed',
        'check_out_time' => now(),
    ]);

    Rider::where('id', $riderId)->update([
        'is_online' => false,
    ]);

    return [
        'success' => true,
        'message' => 'Shift completed successfully.',
        'booking' => $booking->fresh('shift'),
    ];
}

}