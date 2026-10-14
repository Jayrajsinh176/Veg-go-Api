<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\Restaurant\RestaurantAdvertisementService;
use Illuminate\Http\Request;

class CustomerRestaurantController extends Controller
{
    /**
     * Get all approved restaurants for customer.
     */
    public function index(Request $request, RestaurantAdvertisementService $advertisements)
    {
        $restaurants = Restaurant::query()
            ->where('status', 'approved')
            ->with([
                'address',
                'settings',
            ])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->latest()
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

                    'location' => [
                        'latitude' => $restaurant->address?->latitude,
                        'longitude' => $restaurant->address?->longitude,
                    ],
                ];
            })
            ->values();

        // Paid Featured Restaurants (Package 4) first, flagged is_featured
        $restaurants = $advertisements->featuredFirst($restaurants);

        return response()->json([
            'success' => true,
            'message' => 'Restaurants fetched successfully.',
            'data' => [
                'restaurants' => $restaurants,
            ],
        ], 200);
    }

    /**
 * Get approved restaurants by category.
 */
public function byCategory(Request $request, $categoryId, RestaurantAdvertisementService $advertisements)
{
    $restaurants = Restaurant::query()
        ->where('status', 'approved')
        ->whereHas('categories', function ($query) use ($categoryId) {
            $query
                ->where('id', $categoryId)
                ->where('is_active', true);
        })
        ->with([
            'address',
            'settings',
        ])
        ->withAvg('reviews', 'rating')
        ->withCount('reviews')
        ->latest()
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

                'location' => [
                    'latitude' => $restaurant->address?->latitude,
                    'longitude' => $restaurant->address?->longitude,
                ],
            ];
        })
        ->values();

    $restaurants = $advertisements->featuredFirst($restaurants);

    return response()->json([
        'success' => true,
        'message' => 'Restaurants fetched by category successfully.',
        'data' => [
            'category_id' => (int) $categoryId,
            'restaurants' => $restaurants,
        ],
    ], 200);
}

}