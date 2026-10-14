<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantNotification;
use Illuminate\Http\Request;

class RestaurantNotificationController extends Controller
{
    /**
     * Get Restaurant Notifications
     */
    public function index(Request $request)
    {
        $restaurant = $request->user();

        $query = RestaurantNotification::where('restaurant_id', $restaurant->id);

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        $notifications = $query
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Notifications fetched successfully.',
            'data' => [
                'unread_count' => RestaurantNotification::where('restaurant_id', $restaurant->id)
                    ->where('is_read', false)
                    ->count(),

                'counts' => [
                    'all' => RestaurantNotification::where('restaurant_id', $restaurant->id)->count(),
                    'order' => RestaurantNotification::where('restaurant_id', $restaurant->id)->where('type', 'order')->count(),
                    'payment' => RestaurantNotification::where('restaurant_id', $restaurant->id)->where('type', 'payment')->count(),
                    'review' => RestaurantNotification::where('restaurant_id', $restaurant->id)->where('type', 'review')->count(),
                    'promotion' => RestaurantNotification::where('restaurant_id', $restaurant->id)->where('type', 'promotion')->count(),
                ],

                'notifications' => $notifications,
            ],
        ]);
    }
    /**
 * Mark Notification as Read
 */
public function markAsRead(Request $request, RestaurantNotification $notification)
{
    $restaurant = $request->user();

    if ($notification->restaurant_id != $restaurant->id) {
        return response()->json([
            'success' => false,
            'message' => 'Notification not found.',
        ], 404);
    }

    $notification->update([
        'is_read' => true,
        'read_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Notification marked as read.',
        'data' => [
            'id' => $notification->id,
            'is_read' => true,
            'read_at' => $notification->read_at,
        ],
    ]);
}

    /**
 * Mark All Notifications as Read
 */
public function markAllAsRead(Request $request)
{
    $restaurant = $request->user();

    RestaurantNotification::where('restaurant_id', $restaurant->id)
        ->where('is_read', false)
        ->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

    return response()->json([
        'success' => true,
        'message' => 'All notifications marked as read.',
    ]);
}

}