<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\RiderShiftBooking;
use Illuminate\Http\Request;

class AdminRiderShiftController extends Controller
{
    /**
     * Get rider shift bookings
     */
    public function index(Request $request)
    {
        $bookings = RiderShiftBooking::query()
            ->with([
                'rider:id,name,phone,city_id,city_area_id',
                'rider.city:id,name',
                'rider.cityArea:id,name',
                'shift:id,shift_name,start_time,end_time,is_night,status',
            ])
            ->latest('id')
            ->paginate(10);

        $bookings->getCollection()->transform(function ($booking) {

            $rider = $booking->rider;
            $shift = $booking->shift;

            return [
                'id' => $booking->id,

                'rider_id' => $rider?->id,

                'rider_name' => $rider?->name,

                'phone' => $rider?->phone,

                'shift_id' => $shift?->id,

                'shift_name' => $shift?->shift_name,

                'shift_date' => $booking->booking_date?->format('d M Y'),

                'start_time' => $shift?->start_time,

                'end_time' => $shift?->end_time,

                'shift_type' => $shift?->is_night
                    ? 'Night'
                    : $this->getShiftType(
                        $shift?->start_time
                    ),

                'area' =>
                    $rider?->cityArea?->name
                    ?? $rider?->city?->name
                    ?? '—',

                'booking_status' =>
                    $booking->status,

                'status' =>
                    $this->getDisplayStatus(
                        $booking
                    ),

                'check_in_time' =>
                    $booking->check_in_time,

                'check_out_time' =>
                    $booking->check_out_time,
            ];
        });

        return response()->json([
            'message' => 'Rider shifts fetched successfully.',
            'data' => $bookings,
        ]);
    }


    /**
     * Get one rider shift booking
     */
    public function show($id)
    {
        $booking = RiderShiftBooking::query()
            ->with([
                'rider:id,name,phone,city_id,city_area_id',
                'rider.city:id,name',
                'rider.cityArea:id,name',
                'shift:id,shift_name,start_time,end_time,is_night,status',
            ])
            ->find($id);

        if (!$booking) {
            return response()->json([
                'message' => 'Rider shift booking not found.',
            ], 404);
        }

        $rider = $booking->rider;
        $shift = $booking->shift;

        return response()->json([
            'message' => 'Rider shift details fetched successfully.',

            'data' => [
                'id' => $booking->id,

                'rider_id' => $rider?->id,

                'rider_name' => $rider?->name,

                'phone' => $rider?->phone,

                'shift_id' => $shift?->id,

                'shift_name' => $shift?->shift_name,

                'shift_date' =>
                    $booking->booking_date?->format('d M Y'),

                'start_time' =>
                    $shift?->start_time,

                'end_time' =>
                    $shift?->end_time,

                'shift_type' =>
                    $shift?->is_night
                        ? 'Night'
                        : $this->getShiftType(
                            $shift?->start_time
                        ),

                'area' =>
                    $rider?->cityArea?->name
                    ?? $rider?->city?->name
                    ?? '—',

                'booking_status' =>
                    $booking->status,

                'status' =>
                    $this->getDisplayStatus(
                        $booking
                    ),

                'check_in_time' =>
                    $booking->check_in_time,

                'check_out_time' =>
                    $booking->check_out_time,
            ],
        ]);
    }


    /**
     * Convert booking status into frontend status
     */
    private function getDisplayStatus(
        RiderShiftBooking $booking
    ) {
        $status = strtolower(
            (string) $booking->status
        );

        if (
            in_array($status, [
                'cancelled',
                'canceled',
            ])
        ) {
            return 'Cancelled';
        }

        if (
            in_array($status, [
                'completed',
                'complete',
            ])
        ) {
            return 'Completed';
        }

        if (
            in_array($status, [
                'active',
                'checked_in',
                'check_in',
                'in_progress',
            ])
        ) {
            return 'Active';
        }

        return 'Scheduled';
    }


    /**
     * Detect shift type from start time
     */
    private function getShiftType(
        $startTime
    ) {
        if (!$startTime) {
            return 'Morning';
        }

        $hour = (int) date(
            'H',
            strtotime($startTime)
        );

        if ($hour >= 5 && $hour < 12) {
            return 'Morning';
        }

        if ($hour >= 12 && $hour < 18) {
            return 'Evening';
        }

        return 'Night';
    }
}