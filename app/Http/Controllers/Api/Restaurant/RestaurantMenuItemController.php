<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantCategory;
use App\Models\RestaurantMenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class RestaurantMenuItemController extends Controller
{
    /**
     * Add Menu Item
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:restaurant_categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,jfif|max:2048',
            'is_available' => 'nullable|boolean',
            'is_recommended' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $restaurant = $request->user();

        // Ensure category belongs to logged-in restaurant
        $category = RestaurantCategory::where('id', $request->category_id)
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->first();

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found.',
            ], 404);
        }

        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('restaurant/menu-items', 'public');
        }

       $menuItem = RestaurantMenuItem::create([
    'restaurant_id' => $restaurant->id,
    'category_id' => $category->id,
    'name' => $request->name,
    'description' => $request->description,
    'price' => $request->price,
    'discount_price' => $request->discount_price,
    'image' => $imagePath,
    'is_available' => $request->boolean('is_available', true),
    'is_recommended' => $request->boolean('is_recommended', false),
    'is_active' => true,
]);

        return response()->json([
            'success' => true,
            'message' => 'Menu item added successfully.',
            'data' => $menuItem,
        ], 201);
    }

    /**
 * Get Restaurant Menu Items
 */
public function index(Request $request)
{
    $restaurant = $request->user();

 $menuItems = RestaurantMenuItem::with('category')
    ->where('restaurant_id', $restaurant->id)
    ->where('is_active', true)
    ->orderBy('id', 'desc')
    ->get();

    return response()->json([
        'success' => true,
        'message' => 'Menu items fetched successfully.',
        'data' => $menuItems,
    ]);
}

/**
 * Update Menu Item
 */
public function update(Request $request, RestaurantMenuItem $menuItem)
{
    
    $validator = Validator::make($request->all(), [
        'category_id' => 'required|exists:restaurant_categories,id',
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',
        'price' => 'required|numeric|min:0',
        'discount_price' => 'nullable|numeric|min:0',
        'image' => 'nullable|image|mimes:jpg,jpeg,png,jfif|max:2048',
        'is_available' => 'nullable|boolean',
        'is_recommended' => 'nullable|boolean',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }

    $restaurant = $request->user();

    if ($menuItem->restaurant_id != $restaurant->id) {
        return response()->json([
            'success' => false,
            'message' => 'Menu item not found.',
        ], 404);
    }

    $category = RestaurantCategory::where('id', $request->category_id)
        ->where('restaurant_id', $restaurant->id)
        ->where('is_active', true)
        ->first();

    if (!$category) {
        return response()->json([
            'success' => false,
            'message' => 'Category not found.',
        ], 404);
    }

   if ($request->hasFile('image')) {

    if ($menuItem->image && Storage::disk('public')->exists($menuItem->image)) {
        Storage::disk('public')->delete($menuItem->image);
    }

    $menuItem->image = $request->file('image')->store('restaurant/menu-items', 'public');
}

    $menuItem->category_id = $category->id;
    $menuItem->name = $request->name;
    $menuItem->description = $request->description;
    $menuItem->price = $request->price;
    $menuItem->discount_price = $request->discount_price;
    $menuItem->is_available = $request->boolean('is_available', true);
    $menuItem->is_recommended = $request->boolean('is_recommended', false);

    $menuItem->save();

    return response()->json([
        'success' => true,
        'message' => 'Menu item updated successfully.',
        'data' => $menuItem,
    ]);
}

/**
 * Delete Menu Item
 */
public function destroy(Request $request, RestaurantMenuItem $menuItem)
{
    $restaurant = $request->user();

    if ($menuItem->restaurant_id != $restaurant->id) {
        return response()->json([
            'success' => false,
            'message' => 'Menu item not found.',
        ], 404);
    }

    $menuItem->update([
        'is_active' => false,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Menu item deleted successfully.',
    ]);
}

/**
 * Toggle Menu Item Availability
 */
public function toggleAvailability(Request $request, RestaurantMenuItem $menuItem)
{
    $restaurant = $request->user();

    if ($menuItem->restaurant_id != $restaurant->id) {
        return response()->json([
            'success' => false,
            'message' => 'Menu item not found.',
        ], 404);
    }

    $menuItem->update([
        'is_available' => !$menuItem->is_available,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Menu item availability updated successfully.',
        'data' => [
            'id' => $menuItem->id,
            'is_available' => $menuItem->is_available,
        ],
    ]);
}

/**
 * Duplicate Menu Item
 */
public function duplicate(Request $request, RestaurantMenuItem $menuItem)
{
    $restaurant = $request->user();

    if ($menuItem->restaurant_id != $restaurant->id) {
        return response()->json([
            'success' => false,
            'message' => 'Menu item not found.',
        ], 404);
    }

  $newItem = $menuItem->replicate();

$newItem->name = $menuItem->name . ' (Copy)';
$newItem->is_active = true;
$newItem->created_at = now();
$newItem->updated_at = now();

$newItem->save();

    return response()->json([
        'success' => true,
        'message' => 'Menu item duplicated successfully.',
        'data' => $newItem,
    ]);
}

/**
 * Category Details
 */
public function categoryItems(Request $request, RestaurantCategory $category)
{
    $restaurant = $request->user();

    if ($category->restaurant_id != $restaurant->id || !$category->is_active) {
        return response()->json([
            'success' => false,
            'message' => 'Category not found.',
        ], 404);
    }

    $items = RestaurantMenuItem::where('restaurant_id', $restaurant->id)
        ->where('category_id', $category->id)
        ->where('is_active', true)
        ->orderBy('name')
        ->get();

        $totalItems = $items->count();
$availableItems = $items->where('is_available', true)->count();
$unavailableItems = $totalItems - $availableItems;

    return response()->json([
        'success' => true,
        'message' => 'Category items fetched successfully.',
        'data' => [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
            ],
         'summary' => [
    'total_items' => $totalItems,
    'available_items' => $availableItems,
    'unavailable_items' => $unavailableItems,
    'availability_percentage' => $totalItems > 0
        ? round(($availableItems / $totalItems) * 100)
        : 0,
],
            'items' => $items->map(function ($item) {

    return [
        'id' => $item->id,
        'name' => $item->name,
        'description' => $item->description,
        'price' => $item->price,
        'discount_price' => $item->discount_price,
        'image' => $item->image,
        'is_available' => (bool) $item->is_available,
        'is_recommended' => (bool) $item->is_recommended,
        'is_veg' => true,
    ];

}),
        ],
    ]);
}

/**
 * Review Menu
 */
public function reviewMenu(Request $request)
{
    $restaurant = $request->user();

    $categories = RestaurantCategory::where('restaurant_id', $restaurant->id)
        ->where('is_active', true)
        ->with([
            'menuItems' => function ($query) {
                $query->where('is_active', true)
                    ->orderBy('name');
            }
        ])
        ->orderBy('name')
        ->get();

    $totalItems = RestaurantMenuItem::where('restaurant_id', $restaurant->id)
        ->where('is_active', true)
        ->count();

    $itemsWithPhotos = RestaurantMenuItem::where('restaurant_id', $restaurant->id)
        ->where('is_active', true)
        ->whereNotNull('image')
        ->count();

    return response()->json([
        'success' => true,
        'message' => 'Review menu fetched successfully.',
        'data' => [
            'summary' => [
                'total_categories' => $categories->count(),
                'total_items' => $totalItems,
                'items_with_photos' => $itemsWithPhotos,
            ],
            'categories' => $categories,
        ],
    ]);
}

/**
 * Get Single Menu Item
 */
public function show(Request $request, RestaurantMenuItem $menuItem)
{
    $restaurant = $request->user();

    if (
        $menuItem->restaurant_id != $restaurant->id ||
        !$menuItem->is_active
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Menu item not found.',
        ], 404);
    }

    return response()->json([
        'success' => true,
        'message' => 'Menu item fetched successfully.',
        'data' => [
            'id' => $menuItem->id,
            'category_id' => $menuItem->category_id,
            'name' => $menuItem->name,
            'description' => $menuItem->description,
            'price' => $menuItem->price,
            'discount_price' => $menuItem->discount_price,
            'image' => $menuItem->image,
            'is_available' => (bool) $menuItem->is_available,
            'is_recommended' => (bool) $menuItem->is_recommended,
        ],
    ]);
}

}