<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\RestaurantCategory;
use Illuminate\Http\Request;

class CustomerCategoryController extends Controller
{
    /**
     * Get food categories for customer home screen.
     */
    public function index(Request $request)
    {
        $categories = RestaurantCategory::query()
            ->where('is_active', true)
            ->whereHas('restaurant', function ($query) {
                $query->where('status', 'approved');
            })
            ->select('name')
            ->distinct()
            ->orderBy('display_order')
            ->get()
            ->map(function ($category) {
                return [
                    'name' => $category->name,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Categories fetched successfully.',
            'data' => [
                'categories' => $categories,
            ],
        ], 200);
    }
}