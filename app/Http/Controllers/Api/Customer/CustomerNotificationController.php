<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use Illuminate\Http\Request;

class CustomerNotificationController extends Controller
{
    /**
     * Get customer's notifications.
     */
    public function index(Request $request)
    {
        $customer = $request->user();

        $notifications = CustomerNotification::where(
            'customer_id',
            $customer->id
        )
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Notifications fetched successfully.',
            'data' => [
                'notifications' => $notifications,
                'unread_count' => CustomerNotification::where(
                    'customer_id',
                    $customer->id
                )
                    ->where('is_read', false)
                    ->count(),
            ],
        ], 200);
    }

    /**
     * Mark one notification as read.
     */
    public function markAsRead(Request $request, $id)
    {
        $customer = $request->user();

        $notification = CustomerNotification::where(
            'customer_id',
            $customer->id
        )->find($id);

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        $notification->update([
            'is_read' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'data' => [
                'notification' => $notification->fresh(),
            ],
        ], 200);
    }

    /**
     * Mark all customer notifications as read.
     */
    public function markAllAsRead(Request $request)
    {
        $customer = $request->user();

        CustomerNotification::where(
            'customer_id',
            $customer->id
        )
            ->where('is_read', false)
            ->update([
                'is_read' => true,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
        ], 200);
    }
}