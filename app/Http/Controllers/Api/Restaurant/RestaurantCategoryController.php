<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\RestaurantCategory;

class RestaurantCategoryController extends Controller
{
    /**
     * Add Category
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $restaurant = $request->user();

        $category = RestaurantCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => $request->name,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category added successfully.',
            'data' => $category,
        ]);
    }

    /**
 * Get Restaurant Categories
 */
public function index(Request $request)
{
    $restaurant = $request->user();

    $categories = RestaurantCategory::where('restaurant_id', $restaurant->id)
        ->where('is_active', true)
        ->orderBy('id')
        ->get();

    return response()->json([
        'success' => true,
        'message' => 'Categories fetched successfully.',
        'data' => $categories,
    ]);
}

/**
 * Update Category
 */
public function update(Request $request, RestaurantCategory $category)
{
    $validator = Validator::make($request->all(), [
        'name' => 'required|string|max:100',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }

    $restaurant = $request->user();

    if ($category->restaurant_id != $restaurant->id) {
        return response()->json([
            'success' => false,
            'message' => 'Category not found.',
        ], 404);
    }

    $category->update([
        'name' => $request->name,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Category updated successfully.',
        'data' => $category,
    ]);
}

/**
 * Delete Category
 */
public function destroy(Request $request, RestaurantCategory $category)
{
    $restaurant = $request->user();

    if ($category->restaurant_id != $restaurant->id) {
        return response()->json([
            'success' => false,
            'message' => 'Category not found.',
        ], 404);
    }

    $category->update([
        'is_active' => false,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Category deleted successfully.',
    ]);
}
}