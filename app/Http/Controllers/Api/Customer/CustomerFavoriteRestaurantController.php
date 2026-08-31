<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerFavoriteRestaurant;
use Illuminate\Http\Request;

class CustomerFavoriteRestaurantController extends Controller
{
    /**
     * Get customer's favorite restaurants.
     */
public function index(Request $request)
{
    $customer = $request->user();

    $favorites = CustomerFavoriteRestaurant::query()
        ->where('customer_id', $customer->id)
        ->with([
            'restaurant.address',
        ])
        ->latest()
        ->get();

        if ($favorites->isEmpty()) {
    return response()->json([
        'success' => true,
        'message' => 'No favorite restaurants found.',
        'data' => [
            'restaurants' => [],
        ],
    ], 200);
}

    return response()->json([
        'success' => true,
        'message' => 'Favorite restaurants fetched successfully.',
        'data' => [
            'restaurants' => $favorites->map(function ($favorite) {

                $restaurant = $favorite->restaurant;

                $rating = $restaurant
                    ? $restaurant->reviews()->avg('rating')
                    : null;

                $totalReviews = $restaurant
                    ? $restaurant->reviews()->count()
                    : 0;

                return [
                    'favorite_id' => $favorite->id,

                    'restaurant' => [
                        'id' => $restaurant?->id,

                        'name' => $restaurant?->restaurant_name,

                        'image' => $restaurant?->address?->restaurant_front_image,

                        'rating' => $rating !== null
                            ? round((float) $rating, 1)
                            : 0,

                        'total_reviews' => $totalReviews,

                        'is_online' => (bool) $restaurant?->is_online,

                        'is_favorite' => true,
                    ],

                    'created_at' => $favorite->created_at,
                ];
            })->values(),
        ],
    ], 200);
}
    /**
     * Add restaurant to favorites.
     */
    public function store(Request $request, $restaurantId)
    {
        $customer = $request->user();

        $restaurant = \App\Models\Restaurant::find($restaurantId);

        if (!$restaurant) {
            return response()->json([
                'success' => false,
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $favorite = CustomerFavoriteRestaurant::firstOrCreate([
            'customer_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => $favorite->wasRecentlyCreated
                ? 'Restaurant added to favorites.'
                : 'Restaurant is already in favorites.',
            'data' => [
                'favorite' => [
                    'id' => $favorite->id,
                    'restaurant_id' => $favorite->restaurant_id,
                    'restaurant_name' => $restaurant->restaurant_name,
                ],
            ],
        ], 200);
    }

    /**
     * Remove restaurant from favorites.
     */
    public function destroy(Request $request, $restaurantId)
    {
        $customer = $request->user();

        $favorite = CustomerFavoriteRestaurant::where('customer_id', $customer->id)
            ->where('restaurant_id', $restaurantId)
            ->first();

        if (!$favorite) {
            return response()->json([
                'success' => false,
                'message' => 'Restaurant is not in favorites.',
            ], 404);
        }

        $favorite->delete();

        return response()->json([
            'success' => true,
            'message' => 'Restaurant removed from favorites.',
        ], 200);
    }
}