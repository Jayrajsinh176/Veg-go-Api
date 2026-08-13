<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RestaurantCategory;
use App\Models\RestaurantMenuItem;


class RestaurantMenuController extends Controller
{
    /**
     * Menu Dashboard
     */
   public function dashboard(Request $request)
{
    $restaurant = $request->user();

    $totalItems = RestaurantMenuItem::where('restaurant_id', $restaurant->id)
        ->where('is_active', true)
        ->count();

    $availableItems = RestaurantMenuItem::where('restaurant_id', $restaurant->id)
        ->where('is_active', true)
        ->where('is_available', true)
        ->count();

    $totalCategories = RestaurantCategory::where('restaurant_id', $restaurant->id)
        ->where('is_active', true)
        ->count();

    $query = RestaurantCategory::where('restaurant_id', $restaurant->id)
    ->where('is_active', true);

if ($request->filled('search')) {
    $query->where('name', 'like', '%' . $request->search . '%');
}

$categories = $query->orderBy('display_order')
    ->get()
    ->map(function ($category) use ($restaurant) {

        $totalItems = RestaurantMenuItem::where('restaurant_id', $restaurant->id)
            ->where('category_id', $category->id)
            ->where('is_active', true)
            ->count();

        $availableItems = RestaurantMenuItem::where('restaurant_id', $restaurant->id)
            ->where('category_id', $category->id)
            ->where('is_active', true)
            ->where('is_available', true)
            ->count();

        $unavailableItems = $totalItems - $availableItems;

        return [
            'id' => $category->id,
            'name' => $category->name,
            'total_items' => $totalItems,
            'available_items' => $availableItems,
            'unavailable_items' => $unavailableItems,
            'availability_percentage' => $totalItems > 0
                ? round(($availableItems / $totalItems) * 100)
                : 0,
            'is_active' => (bool) $category->is_active,
        ];
    });

    return response()->json([
        'success' => true,
        'message' => 'Menu dashboard fetched successfully.',
        'data' => [
            'summary' => [
                'total_items' => $totalItems,
                'available_items' => $availableItems,
                'total_categories' => $totalCategories,
            ],
           'categories' => $categories,
        ],
    ]);
}
}