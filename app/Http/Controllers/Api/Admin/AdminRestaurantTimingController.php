<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;

class AdminRestaurantTimingController extends Controller
{
    /**
     * Get restaurant timing list
     */
    public function index(Request $request)
    {
        $restaurants = Restaurant::query()
            ->select([
                'id',
                'owner_name',
                'restaurant_name',
                'is_online',
            ])
            ->with([
                'workingHours' => function ($query) {
                    $query->select([
                        'id',
                        'restaurant_id',
                        'day',
                        'slot',
                        'open_time',
                        'close_time',
                    ])
                    ->orderBy('id');
                },
            ])
            ->latest('id')
            ->paginate(10);

        $restaurants->getCollection()->transform(function ($restaurant) {

            $workingHour = $restaurant->workingHours->first();

            return [
                'id' => $restaurant->id,

                'name' => $restaurant->restaurant_name,

                'owner' => $restaurant->owner_name,

                'openingTime' => $workingHour?->open_time,

                'closingTime' => $workingHour?->close_time,

                'status' => $restaurant->is_online
                    ? 'Open'
                    : 'Closed',
            ];
        });

        return response()->json([
            'message' => 'Restaurant timings fetched successfully.',
            'data' => $restaurants,
        ]);
    }


    /**
     * Get single restaurant timing
     */
    public function show($id)
    {
        $restaurant = Restaurant::query()
            ->select([
                'id',
                'owner_name',
                'restaurant_name',
                'is_online',
            ])
            ->with([
                'workingHours' => function ($query) {
                    $query->select([
                        'id',
                        'restaurant_id',
                        'day',
                        'slot',
                        'open_time',
                        'close_time',
                    ])
                    ->orderBy('id');
                },
            ])
            ->find($id);

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $workingHour = $restaurant->workingHours->first();

        return response()->json([
            'message' => 'Restaurant timing fetched successfully.',
            'data' => [
                'id' => $restaurant->id,

                'name' => $restaurant->restaurant_name,

                'owner' => $restaurant->owner_name,

                'openingTime' => $workingHour?->open_time,

                'closingTime' => $workingHour?->close_time,

                'status' => $restaurant->is_online
                    ? 'Open'
                    : 'Closed',

                'working_hours' =>
                    $restaurant->workingHours,
            ],
        ]);
    }


    /**
     * Update restaurant timing
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'opening_time' => ['required', 'date_format:H:i'],
            'closing_time' => ['required', 'date_format:H:i'],
            'status' => ['required', 'in:Open,Closed'],
        ]);

        $restaurant = Restaurant::find($id);

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $workingHour = $restaurant->workingHours()->first();

        if (!$workingHour) {
            return response()->json([
                'message' => 'Restaurant working hours not found.',
            ], 404);
        }

        $workingHour->update([
            'open_time' => $request->opening_time,
            'close_time' => $request->closing_time,
        ]);

        $restaurant->update([
            'is_online' => $request->status === 'Open',
        ]);

        return response()->json([
            'message' => 'Restaurant timing updated successfully.',
            'data' => [
                'id' => $restaurant->id,
                'name' => $restaurant->restaurant_name,
                'owner' => $restaurant->owner_name,
                'openingTime' => $workingHour->open_time,
                'closingTime' => $workingHour->close_time,
                'status' => $restaurant->is_online
                    ? 'Open'
                    : 'Closed',
            ],
        ]);
    }
}