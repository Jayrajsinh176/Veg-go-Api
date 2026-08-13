<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantCategory;
use Illuminate\Http\Request;

class CustomerSearchController extends Controller
{
    /**
     * Search screen.
     *
     * Empty search:
     * - Return all categories
     *
     * Search text:
     * - Search restaurant name
     * - Search category name
     * - Search menu item name
     * - Search menu item description
     */
    public function index(Request $request)
    {
        $search = trim($request->input('q', ''));

        /*
        |--------------------------------------------------------------------------
        | Empty Search
        |--------------------------------------------------------------------------
        */

        if ($search === '') {

            $categories = RestaurantCategory::query()
                ->where('is_active', true)
                ->select('id', 'name')
                ->distinct()
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Search categories fetched successfully.',
                'data' => [
                    'categories' => $categories,
                ],
            ], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | Search Restaurants
        |--------------------------------------------------------------------------
        */

        $restaurants = Restaurant::query()
            ->where('status', 'approved')
            ->where(function ($query) use ($search) {

                // Restaurant name
                $query->where(
                    'restaurant_name',
                    'like',
                    "%{$search}%"
                )

                // Category name
                ->orWhereHas('categories', function ($categoryQuery) use ($search) {
                    $categoryQuery
                        ->where('is_active', true)
                        ->where('name', 'like', "%{$search}%");
                })

                // Menu item name / description
                ->orWhereHas('menuItems', function ($menuQuery) use ($search) {
                    $menuQuery
                        ->where('is_active', true)
                        ->where('is_available', true)
                        ->where(function ($itemQuery) use ($search) {
                            $itemQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere(
                                    'description',
                                    'like',
                                    "%{$search}%"
                                );
                        });
                });
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

        return response()->json([
            'success' => true,
            'message' => 'Search results fetched successfully.',
            'data' => [
                'search' => $search,
                'restaurants' => $restaurants,
            ],
        ], 200);
    }
}