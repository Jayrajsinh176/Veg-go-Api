<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantAdvertisementEvent;
use App\Models\RestaurantCategory;
use App\Services\Restaurant\RestaurantAdvertisementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerHomeController extends Controller
{
    /**
     * Customer home screen.
     */
    public function index(Request $request, RestaurantAdvertisementService $advertisements)
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
        | Paid Advertisements
        |--------------------------------------------------------------------------
        | Featured restaurants (Package 4) go first in the list, Home banners
        | (Package 1) and promoted food items (Package 2) get their own sections.
        */
        $restaurants = $advertisements->featuredFirst($restaurants);

        $homeAdvertisements = $advertisements->homeAdvertisements()
            ->map(fn ($ad) => $advertisements->formatForCustomer($ad))
            ->values();

        $promotedFoodItems = $advertisements->promotedFoodItems()
            ->map(fn ($ad) => $advertisements->formatForCustomer($ad))
            ->values();

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

                'home_advertisements' => $homeAdvertisements,

                'promoted_food_items' => $promotedFoodItems,

                'featured_restaurants' => $restaurants->where('is_featured', true)->values(),

                'restaurants' => $restaurants,
            ],

        ], 200);
    }

    /**
     * The app reports ads it showed (impression) or the customer tapped
     * (click). Body: { "events": [ { "advertisement_id": 12, "event": "impression" } ] }
     */
    public function trackAdvertisements(Request $request, RestaurantAdvertisementService $advertisements)
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.advertisement_id' => ['required', 'integer', 'min:1'],
            'events.*.event' => ['required', Rule::in(RestaurantAdvertisementEvent::events())],
        ]);

        $recorded = $advertisements->recordEvents($request->user()->id, $validated['events']);

        return response()->json([
            'success' => true,
            'message' => 'Advertisement events recorded.',
            'data' => ['recorded' => $recorded],
        ]);
    }
}