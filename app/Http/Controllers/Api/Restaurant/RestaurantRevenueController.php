<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RestaurantCategory;

class RestaurantRevenueController extends Controller
{
    public function summary(Request $request)
    {
        $type = $request->query('type', 'daily');

        $titles = [
            'daily'   => "Today's Revenue",
            'weekly'  => "This Week's Revenue",
            'monthly' => "This Month's Revenue",
        ];

        $comparisons = [
            'daily'   => 'vs last day',
            'weekly'  => 'vs last week',
            'monthly' => 'vs last month',
        ];

        $data = [
            'period' => $type,
            'title' => $titles[$type] ?? "Today's Revenue",
            'total_revenue' => 0,
            'growth_percentage' => 0,
            'growth_type' => 'neutral',
            'comparison_text' => $comparisons[$type] ?? 'vs previous period',
            'average_revenue' => 0,
            'total_orders' => 0,
            'average_order_value' => 0,
            'currency' => 'INR',
        ];

        return response()->json([
            'success' => true,
            'message' => 'Revenue summary fetched successfully.',
            'data' => $data,
        ]);
    }

    public function chart(Request $request)
{
    $type = $request->query('type', 'daily');

    switch ($type) {

        case 'weekly':

            $data = [
                'period' => 'weekly',
                'labels' => [
                    'Mon',
                    'Tue',
                    'Wed',
                    'Thu',
                    'Fri',
                    'Sat',
                    'Sun',
                ],
                'revenues' => [
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                ],
                'currency' => 'INR',
            ];

            break;

        case 'monthly':

            $data = [
                'period' => 'monthly',
                'labels' => [
                    'Week 1',
                    'Week 2',
                    'Week 3',
                    'Week 4',
                ],
                'revenues' => [
                    0,
                    0,
                    0,
                    0,
                ],
                'currency' => 'INR',
            ];

            break;

        default:

            $data = [
                'period' => 'daily',
                'labels' => [
                    '09 AM',
                    '11 AM',
                    '01 PM',
                    '03 PM',
                    '05 PM',
                    '07 PM',
                    '09 PM',
                ],
                'revenues' => [
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                    0,
                ],
                'currency' => 'INR',
            ];
    }

    return response()->json([
        'success' => true,
        'message' => 'Revenue chart fetched successfully.',
        'data' => $data,
    ]);
}

public function breakdown(Request $request)
{
    $type = $request->query('type', 'daily');

    switch ($type) {

        case 'weekly':

            $data = [
                'period' => 'weekly',
                'breakdown' => [
                    [
                        'title' => 'Monday',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => 'Tuesday',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => 'Wednesday',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => 'Thursday',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => 'Friday',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => 'Saturday',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => 'Sunday',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                ],
                'currency' => 'INR',
            ];

            break;

        case 'monthly':

            $data = [
                'period' => 'monthly',
                'breakdown' => [
                    [
                        'title' => 'Week 1',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => 'Week 2',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => 'Week 3',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => 'Week 4',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                ],
                'currency' => 'INR',
            ];

            break;

        default:

            $data = [
                'period' => 'daily',
                'breakdown' => [
                    [
                        'title' => '09 AM',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => '11 AM',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => '01 PM',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => '03 PM',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => '05 PM',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => '07 PM',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                    [
                        'title' => '09 PM',
                        'revenue' => 0,
                        'orders' => 0,
                    ],
                ],
                'currency' => 'INR',
            ];

            break;
    }

    return response()->json([
        'success' => true,
        'message' => 'Revenue breakdown fetched successfully.',
        'data' => $data,
    ]);
}

public function categories(Request $request)
{
    $restaurant = auth()->user();

    $type = $request->query('type', 'daily');

    $categories = RestaurantCategory::where('restaurant_id', $restaurant->id)
        ->where('is_active', true)
        ->orderBy('display_order')
        ->get();

    $data = $categories->map(function ($category) {

        return [
            'category_id' => $category->id,
            'category_name' => $category->name,
            'revenue' => 0,
            'orders' => 0,
            'percentage' => 0,
        ];

    });

    return response()->json([
        'success' => true,
        'message' => 'Revenue categories fetched successfully.',
        'data' => [
            'period' => $type,
            'categories' => $data,
            'currency' => 'INR',
        ],
    ]);
}

}