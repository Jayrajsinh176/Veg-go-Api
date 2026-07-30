<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RestaurantDashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        $restaurant = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'Restaurant dashboard fetched successfully.',
            'data' => [
                'restaurant' => [
                    'id' => $restaurant->id,
                    'name' => $restaurant->restaurant_name,
                    'owner_name' => $restaurant->owner_name,
                    'status' => $restaurant->status,
                    'is_online' => $restaurant->is_online,
                ],

                'today' => now()->format('d M Y'),

                'orders' => [
                    'today_orders' => 0,
                    'active_orders' => 0,
                    'completed_orders' => 0,
                    'cancelled_orders' => 0,
                ],
            ],
        ]);
    }

    public function updateAvailability(Request $request)
{
    $request->validate([
        'is_online' => 'required|boolean',
    ]);

    $restaurant = $request->user();

    // Restaurant must be approved before going online
    if ($restaurant->status !== 'approved') {
      return response()->json([
    'success' => false,
    'message' => 'Your restaurant is not approved yet.',
    'data' => [
        'status' => $restaurant->status,
        'is_online' => $restaurant->is_online,
    ],
], 403);
    }

    $restaurant->update([
        'is_online' => $request->is_online,
    ]);

    return response()->json([
        'success' => true,
        'message' => $restaurant->is_online
            ? 'Restaurant is now online.'
            : 'Restaurant is now offline.',
        'data' => [
            'is_online' => $restaurant->is_online,
        ],
    ]);
}

public function revenueOverview(Request $request)
{
    return response()->json([
        'success' => true,
        'message' => 'Revenue overview fetched successfully.',
        'data' => [
            'today_revenue' => 0,
            'weekly_revenue' => 0,
            'monthly_revenue' => 0,
            'currency' => 'INR',
        ],
    ]);
}

public function performance(Request $request)
{
    $restaurant = $request->user();

    if ($restaurant->status !== 'approved') {

        return response()->json([
            'success' => true,
            'message' => 'Performance fetched successfully.',
            'data' => [
                'title' => 'Application Under Review',
                'description' => 'Your restaurant is currently under review. Once approved, performance insights will be available.',
                'type' => 'pending',
            ],
        ]);
    }

    return response()->json([
        'success' => true,
        'message' => 'Performance fetched successfully.',
        'data' => [
            'title' => 'Keep up the great work!',
            'description' => 'Your restaurant performance is looking good.',
            'type' => 'success',
        ],
    ]);
}

public function ratings(Request $request)
{
    return response()->json([
        'success' => true,
        'message' => 'Restaurant ratings fetched successfully.',
        'data' => [
            'average_rating' => 0,
            'total_reviews' => 0,
        ],
    ]);
}

public function topSellingItems(Request $request)
{
    return response()->json([
        'success' => true,
        'message' => 'Top selling items fetched successfully.',
        'data' => [],
    ]);
}

}