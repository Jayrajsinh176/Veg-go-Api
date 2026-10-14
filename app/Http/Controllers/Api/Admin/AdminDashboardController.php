<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\Customer;
use App\Models\Rider;
use App\Models\RestaurantOrder;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    /**
     * Get admin dashboard data
     */
    public function index(Request $request)
    {
        $totalRestaurants = Restaurant::count();

        $totalCustomers = Customer::count();

        $totalRiders = Rider::count();

        $totalOrders = RestaurantOrder::count();

        $totalRevenue = RestaurantOrder::query()
            ->whereNotIn('order_status', [
                'cancelled',
                'canceled',
            ])
            ->sum('total_amount');

        $recentOrders = RestaurantOrder::query()
            ->select([
                'id',
                'order_number',
                'customer_id',
                'restaurant_id',
                'subtotal',
                'total_amount',
                'order_status',
                'placed_at',
            ])
            ->with([
                'customer:id,name',
                'restaurant:id,restaurant_name',
                'items:id,order_id,menu_item_name,quantity',
            ])
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(function ($order) {

                $itemNames = $order->items
                    ->map(function ($item) {
                        return $item->menu_item_name;
                    })
                    ->filter()
                    ->values();

                $itemText = $itemNames->implode(' & ');

                return [
                    'id' => $order->order_number
                        ? '#' . $order->order_number
                        : '#' . $order->id,

                    'customer' =>
                        $order->customer?->name ?? '—',

                    'restaurant' =>
                        $order->restaurant?->restaurant_name ?? '—',

                    'item' =>
                        $itemText ?: '—',

                    'amount' =>
                        (float) $order->total_amount,

                    'status' =>
                        $order->order_status,

                    'placed_at' =>
                        $order->placed_at,
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Dashboard data fetched successfully.',

            'data' => [
                'summary' => [
                    'total_restaurants' => $totalRestaurants,
                    'total_customers' => $totalCustomers,
                    'total_riders' => $totalRiders,
                    'total_orders' => $totalOrders,
                    'total_revenue' => (float) $totalRevenue,
                ],

                'recent_orders' => $recentOrders,
            ],
        ]);
    }
}