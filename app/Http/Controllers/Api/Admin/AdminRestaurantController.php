<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;

class AdminRestaurantController extends Controller
{
    /**
     * Get restaurant list
     */
    public function index(Request $request)
    {
        $restaurants = Restaurant::query()
            ->select([
                'id',
                'owner_name',
                'restaurant_name',
                'mobile',
                'email',
                'status',
                'is_online',
                'is_profile_completed',
                'created_at',
                'updated_at',
            ])
            ->latest('id')
            ->paginate(10);

        return response()->json([
            'message' => 'Restaurants fetched successfully.',
            'data' => $restaurants,
        ]);
    }
}