<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantCategory;
use Illuminate\Http\Request;

class CustomerHomeController extends Controller
{
    /**
     * Customer home screen.
     */
    public function index(Request $request)
    {
        $customer = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Default Delivery Address
        |--------------------------------------------------------------------------
        */
        $defaultAddress = $customer->addresses()
            ->where('is_default', true)
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Categories
        |--------------------------------------------------------------------------
        */
        $categories = RestaurantCategory::query()
            ->where('is_active', true)
            ->whereHas('restaurant', function ($query) {
                $query->where('status', 'approved');
            })
            ->select('name')
            ->distinct()
            ->orderBy('name')
            ->get()
            ->map(function ($category) {
                return [
                    'name' => $category->name,
                ];
            })
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Restaurants
        |--------------------------------------------------------------------------
        */
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
            });

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */
        return response()->json([
            'success' => true,
            'message' => 'Customer home data.',

            'data' => [

                'delivery_address' => $defaultAddress ? [
                    'id' => $defaultAddress->id,
                    'label' => $defaultAddress->label,
                    'address' => $defaultAddress->address,
                    'landmark' => $defaultAddress->landmark,
                    'pincode' => $defaultAddress->pincode,
                    'latitude' => $defaultAddress->latitude,
                    'longitude' => $defaultAddress->longitude,
                ] : null,

                'categories' => $categories,

                'restaurants' => $restaurants,
            ],

        ], 200);
    }
}