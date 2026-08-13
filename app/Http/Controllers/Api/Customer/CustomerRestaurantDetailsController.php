<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;

class CustomerRestaurantDetailsController extends Controller
{
    /**
     * Get restaurant details for customer.
     */
    public function show(Request $request, $id)
    {
        $restaurant = Restaurant::query()
            ->where('id', $id)
            ->where('status', 'approved')
            ->with([
                'address',
                'settings',
                'categories' => function ($query) {
                    $query->where('is_active', true)
                        ->orderBy('display_order');
                },
                'categories.menuItems' => function ($query) {
                    $query->where('is_active', true)
                        ->where('is_available', true);
                },
            ])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->first();

        if (!$restaurant) {
            return response()->json([
                'success' => false,
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $categories = $restaurant->categories
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,

                    'menu_items' => $category->menuItems
                        ->map(function ($item) {
                            return [
                                'id' => $item->id,
                                'name' => $item->name,
                                'description' => $item->description,
                                'price' => $item->price,
                                'discount_price' => $item->discount_price,
                                'image' => $item->image,
                                'is_recommended' => (bool) $item->is_recommended,
                            ];
                        })
                        ->values(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Restaurant details fetched successfully.',
            'data' => [
                'restaurant' => [
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

                    'categories' => $categories,
                ],
            ],
        ], 200);
    }
}