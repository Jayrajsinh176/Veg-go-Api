<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;

class CustomerPopularController extends Controller
{
    /**
     * Popular restaurants for customer home screen.
     *
     * Final popularity logic will be based on today's
     * restaurant order performance once order data is available.
     */
    public function index(Request $request)
    {
     $restaurants = Restaurant::query()
    ->where('status', 'approved')
    ->with([
        'address',
        'settings',
    ])
    ->withAvg('reviews', 'rating')
    ->withCount('reviews')
    ->withCount([
        'orders as today_orders_count' => function ($query) {
            $query
                ->whereDate('placed_at', today())
                ->where('order_status', '!=', 'cancelled');
        }
    ])
    ->orderByDesc('today_orders_count')
    ->take(6)
    ->get()
            ->map(function ($restaurant) {

                return [
                    'id' => $restaurant->id,

                    'name' => $restaurant->restaurant_name,

                    'image' => $restaurant->address?->restaurant_front_image,

                    'rating' => $restaurant->reviews_avg_rating
                        ? round((float) $restaurant->reviews_avg_rating, 1)
                        : 0,

                    'total_reviews' => $restaurant->reviews_count,

                    'is_online' => (bool) $restaurant->is_online,

                    'prep_time_minutes' =>
                        $restaurant->settings?->default_prep_time ?? 30,

                        'today_orders_count' => $restaurant->today_orders_count,

                    'location' => [
                        'latitude' => $restaurant->address?->latitude,
                        'longitude' => $restaurant->address?->longitude,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Popular restaurants fetched successfully.',
            'data' => [
                'restaurants' => $restaurants,
            ],
        ], 200);
    }

    /**
 * Get all popular restaurants for customer.
 */
public function all(Request $request)
{
    $restaurants = Restaurant::query()
        ->where('status', 'approved')
        ->with([
            'address',
            'settings',
        ])
        ->withAvg('reviews', 'rating')
        ->withCount('reviews')
        ->withCount([
            'orders as today_orders_count' => function ($query) {
                $query
                    ->whereDate('placed_at', today())
                    ->where('order_status', '!=', 'cancelled');
            }
        ])
        ->orderByDesc('today_orders_count')
        ->get()
        ->map(function ($restaurant) {

            return [
                'id' => $restaurant->id,

                'name' => $restaurant->restaurant_name,

                'image' => $restaurant->address?->restaurant_front_image,

                'rating' => $restaurant->reviews_avg_rating
                    ? round((float) $restaurant->reviews_avg_rating, 1)
                    : 0,

                'total_reviews' => $restaurant->reviews_count,

                'is_online' => (bool) $restaurant->is_online,

                'prep_time_minutes' =>
                    $restaurant->settings?->default_prep_time ?? 30,

                'today_orders_count' => $restaurant->today_orders_count,

                'location' => [
                    'latitude' => $restaurant->address?->latitude,
                    'longitude' => $restaurant->address?->longitude,
                ],
            ];
        })
        ->values();

    return response()->json([
        'success' => true,
        'message' => 'All popular restaurants fetched successfully.',
        'data' => [
            'restaurants' => $restaurants,
        ],
    ], 200);
}

}