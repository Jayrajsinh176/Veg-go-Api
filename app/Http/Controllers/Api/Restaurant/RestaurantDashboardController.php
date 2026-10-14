<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RestaurantOrder;

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

// Restaurant cannot go offline if active orders exist
if (!$request->is_online) {

    $hasActiveOrders = RestaurantOrder::where('restaurant_id', $restaurant->id)->visibleToRestaurant()
        ->whereIn('order_status', [
            'pending',
            'accepted',
            'preparing',
            'ready_for_pickup',
        ])
        ->exists();

    if ($hasActiveOrders) {
        return response()->json([
            'success' => false,
            'message' => 'You cannot go offline while there are active orders.',
        ], 422);
    }
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
    $restaurant = auth()->user();

    $search = $request->query('search');
    $categoryId = $request->query('category');
    $sort = $request->query('sort', 'orders');

    $categories = \App\Models\RestaurantCategory::where('restaurant_id', $restaurant->id)
        ->where('is_active', true)
        ->orderBy('display_order')
        ->get()
        ->map(function ($category) {
            return [
                'id' => $category->id,
                'name' => $category->name,
            ];
        });

    return response()->json([
        'success' => true,
        'message' => 'Top selling items fetched successfully.',
        'data' => [
            'summary' => [
                'total_orders' => 0,
                'total_revenue' => 0,
                'total_products' => 0,
            ],
            'filters' => [
                'search' => $search,
                'selected_category' => $categoryId,
                'sort_by' => $sort,
                'categories' => array_merge([
                    [
                        'id' => 0,
                        'name' => 'All',
                    ],
                ], $categories->toArray()),
            ],
            'products' => [],
        ],
    ]);
}

}