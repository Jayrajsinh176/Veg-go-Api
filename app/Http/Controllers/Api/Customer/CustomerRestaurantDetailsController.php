<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use App\Models\RestaurantOrderItem;
use Illuminate\Support\Facades\DB;

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

    public function bestSellers(Request $request, $id)
{
    $restaurant = Restaurant::query()
        ->where('id', $id)
        ->where('status', 'approved')
        ->first();

    if (!$restaurant) {
        return response()->json([
            'success' => false,
            'message' => 'Restaurant not found.',
        ], 404);
    }

    $bestSellers =RestaurantOrderItem::query()
        ->whereHas('order', function ($query) use ($id) {
            $query
                ->where('restaurant_id', $id)
                ->where('order_status', '!=', 'cancelled');
        })
        ->select(
            'menu_item_id',
            'menu_item_name',
            DB::raw('SUM(quantity) as total_quantity_sold')
        )
        ->groupBy(
            'menu_item_id',
            'menu_item_name'
        )
        ->orderByDesc('total_quantity_sold')
        ->take(3)
        ->get();

    return response()->json([
        'success' => true,
        'message' => 'Best sellers fetched successfully.',
        'data' => [
            'restaurant_id' => (int) $id,
            'best_sellers' => $bestSellers,
        ],
    ], 200);
}

/**
 * Get popular categories for customer.
 */
public function popularCategories(Request $request, $id)
{
  $restaurant = Restaurant::query()
    ->where('id', $id)
    ->where('status', 'approved')
    ->with([
        'categories' => function ($query) {
            $query->where('is_active', true);
        }
    ])
    ->first();

if (!$restaurant) {
    return response()->json([
        'success' => false,
        'message' => 'Restaurant not found.',
    ], 404);
}

$categoryIds = $restaurant->categories->pluck('id');

$popularCategories = \App\Models\RestaurantOrderItem::query()
    ->whereHas('order', function ($query) use ($id) {
        $query
            ->where('restaurant_id', $id)
            ->where('order_status', '!=', 'cancelled');
    })
    ->whereHas('menuItem', function ($query) use ($categoryIds) {
        $query->whereIn('category_id', $categoryIds);
    })
    ->with('menuItem.category')
    ->get()
    ->groupBy(function ($item) {
        return $item->menuItem?->category_id;
    })
    ->map(function ($items, $categoryId) {

        $category = $items->first()->menuItem?->category;

        return [
            'id' => $categoryId,
            'name' => $category?->name,
            'total_quantity_sold' => $items->sum('quantity'),
        ];
    })
    ->sortByDesc('total_quantity_sold')
    ->take(6)
    ->values();

return response()->json([
    'success' => true,
    'message' => 'Popular categories fetched successfully.',
    'data' => [
        'restaurant_id' => (int) $id,
        'categories' => $popularCategories,
    ],
], 200);
}

/**
 * Get restaurant menu for customer.
 */
public function menu(Request $request, $id)
{
 $restaurant = Restaurant::query()
    ->where('id', $id)
    ->where('status', 'approved')
    ->with([
        'categories' => function ($query) {
            $query
                ->where('is_active', true)
                ->orderBy('display_order');
        },
        'categories.menuItems' => function ($query) {
            $query
                ->where('is_active', true)
                ->where('is_available', true);
        },
    ])
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
    'message' => 'Restaurant menu fetched successfully.',
    'data' => [
        'restaurant_id' => (int) $id,
        'categories' => $categories,
    ],
], 200);
}

/**
 * Search restaurant menu for customer.
 */
public function menuSearch(Request $request, $id)
{
$search = trim($request->query('q', ''));

$restaurant = Restaurant::query()
    ->where('id', $id)
    ->where('status', 'approved')
    ->first();

if (!$restaurant) {
    return response()->json([
        'success' => false,
        'message' => 'Restaurant not found.',
    ], 404);
}

if ($search === '') {
    return response()->json([
        'success' => false,
        'message' => 'Search query is required.',
    ], 422);
}

$menuItems = \App\Models\RestaurantMenuItem::query()
    ->where('restaurant_id', $id)
    ->where('is_active', true)
    ->where('is_available', true)
    ->where(function ($query) use ($search) {
        $query->where('name', 'like', "%{$search}%")
            ->orWhere('description', 'like', "%{$search}%");
    })
    ->get()
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
    ->values();

return response()->json([
    'success' => true,
    'message' => 'Menu search results fetched successfully.',
    'data' => [
        'restaurant_id' => (int) $id,
        'search' => $search,
        'menu_items' => $menuItems,
    ],
], 200);
}

public function categoryMenu(Request $request, $id, $categoryId)
{
   $restaurant = Restaurant::query()
    ->where('id', $id)
    ->where('status', 'approved')
    ->first();

if (!$restaurant) {
    return response()->json([
        'success' => false,
        'message' => 'Restaurant not found.',
    ], 404);
}

$category = \App\Models\RestaurantCategory::query()
    ->where('id', $categoryId)
    ->where('restaurant_id', $id)
    ->where('is_active', true)
    ->first();

if (!$category) {
    return response()->json([
        'success' => false,
        'message' => 'Category not found for this restaurant.',
    ], 404);
}

$menuItems = \App\Models\RestaurantMenuItem::query()
    ->where('restaurant_id', $id)
    ->where('category_id', $categoryId)
    ->where('is_active', true)
    ->where('is_available', true)
    ->get()
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
    ->values();

return response()->json([
    'success' => true,
    'message' => 'Category menu fetched successfully.',
    'data' => [
        'restaurant_id' => (int) $id,
        'category' => [
            'id' => $category->id,
            'name' => $category->name,
        ],
        'menu_items' => $menuItems,
    ],
], 200);
}

/**
 * Get menu item details for customer.
 */
public function menuItem(Request $request, $id, $itemId)
{
   $restaurant = Restaurant::query()
    ->where('id', $id)
    ->where('status', 'approved')
    ->first();

if (!$restaurant) {
    return response()->json([
        'success' => false,
        'message' => 'Restaurant not found.',
    ], 404);
}

$item = \App\Models\RestaurantMenuItem::query()
    ->where('id', $itemId)
    ->where('restaurant_id', $id)
    ->where('is_active', true)
    ->where('is_available', true)
    ->with('category')
    ->first();

if (!$item) {
    return response()->json([
        'success' => false,
        'message' => 'Menu item not found.',
    ], 404);
}

return response()->json([
    'success' => true,
    'message' => 'Menu item details fetched successfully.',
    'data' => [
        'restaurant_id' => (int) $id,
        'item' => [
            'id' => $item->id,
            'category' => [
                'id' => $item->category?->id,
                'name' => $item->category?->name,
            ],
            'name' => $item->name,
            'description' => $item->description,
            'price' => $item->price,
            'discount_price' => $item->discount_price,
            'image' => $item->image,
            'is_recommended' => (bool) $item->is_recommended,
            'is_available' => (bool) $item->is_available,
        ],
    ],
], 200);
}

}