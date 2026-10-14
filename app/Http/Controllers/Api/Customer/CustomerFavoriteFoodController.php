<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerFavoriteFood;
use App\Models\RestaurantMenuItem;
use Illuminate\Http\Request;

class CustomerFavoriteFoodController extends Controller
{
    /**
     * Get customer's favorite foods.
     */
   public function index(Request $request)
{
    $customer = $request->user();

    $favorites = CustomerFavoriteFood::with([
        'menuItem.restaurant',
    ])
        ->where('customer_id', $customer->id)
        ->latest()
        ->get();

    if ($favorites->isEmpty()) {
        return response()->json([
            'success' => true,
            'message' => 'No favorite foods found.',
            'data' => [
                'foods' => [],
            ],
        ], 200);
    }

    return response()->json([
        'success' => true,
        'message' => 'Favorite foods fetched successfully.',
        'data' => [
            'foods' => $favorites->map(function ($favorite) {

                $food = $favorite->menuItem;
                $restaurant = $food?->restaurant;

                $sellingPrice = $food?->discount_price !== null
                    ? $food->discount_price
                    : $food?->price;

                return [
                    'favorite_id' => $favorite->id,

                    'food' => [
                        'id' => $food?->id,
                        'name' => $food?->name,
                        'description' => $food?->description,
                        'price' => $sellingPrice,
                        'original_price' => $food?->price,
                        'offer_label' => $food?->offer_label,
                        'image' => $food?->image,
                        'is_available' => (bool) $food?->is_available,
                        'is_recommended' => (bool) $food?->is_recommended,
                        'is_favorite' => true,
                    ],

                    'restaurant' => [
                        'id' => $restaurant?->id,
                        'name' => $restaurant?->restaurant_name,
                    ],

                    'created_at' => $favorite->created_at,
                ];
            })->values(),
        ],
    ], 200);
}

    /**
     * Add food to favorites.
     */
    public function store(Request $request, $menuItemId)
    {
        $customer = $request->user();

        $menuItem = RestaurantMenuItem::with('restaurant')
            ->find($menuItemId);

        if (!$menuItem) {
            return response()->json([
                'success' => false,
                'message' => 'Food item not found.',
            ], 404);
        }

        if (!$menuItem->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This food item is no longer available.',
            ], 422);
        }

        $favorite = CustomerFavoriteFood::firstOrCreate([
            'customer_id' => $customer->id,
            'menu_item_id' => $menuItem->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => $favorite->wasRecentlyCreated
                ? 'Food added to favorites.'
                : 'Food is already in favorites.',
            'data' => [
                'favorite' => [
                    'id' => $favorite->id,
                    'menu_item_id' => $menuItem->id,
                    'food_name' => $menuItem->name,
                    'restaurant' => [
                        'id' => $menuItem->restaurant?->id,
                        'name' => $menuItem->restaurant?->restaurant_name,
                    ],
                ],
            ],
        ], 200);
    }

    /**
     * Remove food from favorites.
     */
    public function destroy(Request $request, $menuItemId)
    {
        $customer = $request->user();

        $favorite = CustomerFavoriteFood::where('customer_id', $customer->id)
            ->where('menu_item_id', $menuItemId)
            ->first();

        if (!$favorite) {
            return response()->json([
                'success' => false,
                'message' => 'Food is not in favorites.',
            ], 404);
        }

        $favorite->delete();

        return response()->json([
            'success' => true,
            'message' => 'Food removed from favorites.',
        ], 200);
    }
}