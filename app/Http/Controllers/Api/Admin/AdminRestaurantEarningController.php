<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantOrder;
use App\Models\RestaurantWalletTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminRestaurantEarningController extends Controller
{
    /**
     * Restaurant earnings list (orders, gross sales, commission, net earnings)
     *
     * Figures follow RestaurantOrderService: commission is taken on the
     * order food subtotal. Credited orders use the stored commission; pending
     * orders show what it will be at the restaurant's current rate
     *
     * range: 7d | 30d | 90d | year | all
     */
    public function index(Request $request)
    {
        $commissionRate = (float) config('restaurant.commission_percentage', 30.00);

        $startDate = $this->rangeStart($request->query('range', '30d'));

        // Each restaurant's own rate, else the default 30%
        $rateSql = "COALESCE(restaurants.commission_percentage, {$commissionRate})";

        $pendingCommissionSql = "ROUND(restaurant_orders.subtotal * {$rateSql} / 100, 2)";

        $totals = RestaurantOrder::query()
            ->join('restaurants', 'restaurants.id', '=', 'restaurant_orders.restaurant_id')
            ->where('restaurant_orders.order_status', '!=', 'cancelled')
            ->where('restaurant_orders.earning_status', '!=', 'reversed')
            ->when($startDate, fn ($query) => $query->where(DB::raw('COALESCE(restaurant_orders.placed_at, restaurant_orders.created_at)'), '>=', $startDate))
            ->groupBy('restaurant_orders.restaurant_id')
            ->select([
                'restaurant_orders.restaurant_id',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(restaurant_orders.total_amount) as gross_sales'),
                DB::raw("SUM(CASE WHEN restaurant_orders.earning_status = 'pending' THEN {$pendingCommissionSql} ELSE restaurant_orders.commission_amount END) as commission"),
                DB::raw("SUM(CASE WHEN restaurant_orders.earning_status = 'credited' THEN restaurant_orders.restaurant_earning ELSE 0 END) as paid_amount"),
                DB::raw("SUM(CASE WHEN restaurant_orders.earning_status = 'pending' THEN restaurant_orders.subtotal - {$pendingCommissionSql} ELSE 0 END) as pending_amount"),
                DB::raw("SUM(CASE WHEN restaurant_orders.earning_status = 'credited' THEN 1 ELSE 0 END) as credited_orders"),
                DB::raw("SUM(CASE WHEN restaurant_orders.earning_status = 'pending' THEN 1 ELSE 0 END) as pending_orders"),
            ])
            ->get()
            ->keyBy('restaurant_id');

        $lastPayouts = RestaurantWalletTransaction::query()
            ->where('type', 'credit')
            ->groupBy('restaurant_id')
            ->select('restaurant_id', DB::raw('MAX(created_at) as last_payout_at'))
            ->pluck('last_payout_at', 'restaurant_id');

        $restaurants = Restaurant::query()
            ->where(function ($query) use ($totals) {
                $query->where('status', 'approved')
                    ->orWhereIn('id', $totals->keys());
            })
            ->orderBy('restaurant_name')
            ->get([
                'id',
                'restaurant_name',
                'owner_name',
                'mobile',
                'email',
                'status',
                'wallet_balance',
                'commission_percentage',
                'created_at',
            ])
            ->map(function ($restaurant) use ($totals, $lastPayouts, $commissionRate) {
                $row = $totals->get($restaurant->id);

                $grossSales = round((float) ($row->gross_sales ?? 0), 2);
                $commission = round((float) ($row->commission ?? 0), 2);
                $creditedOrders = (int) ($row->credited_orders ?? 0);
                $pendingOrders = (int) ($row->pending_orders ?? 0);

                // Effective rate on this period's orders (falls back to the restaurant's rate)
                $effectiveRate = $grossSales > 0
                    ? round(($commission / $grossSales) * 100, 2)
                    : $restaurant->commissionRate();

                $payoutStatus = match (true) {
                    $creditedOrders > 0 && $pendingOrders === 0 => 'paid',
                    $creditedOrders > 0 && $pendingOrders > 0 => 'processing',
                    default => 'pending',
                };

                return [
                    'id' => $restaurant->id,
                    'name' => $restaurant->restaurant_name,
                    'owner_name' => $restaurant->owner_name,
                    'email' => $restaurant->email,
                    'mobile' => $restaurant->mobile,
                    'account_status' => $restaurant->status,
                    'wallet_balance' => (float) $restaurant->wallet_balance,
                    'joined_at' => $restaurant->created_at,
                    'orders' => (int) ($row->orders ?? 0),
                    'gross_sales' => $grossSales,
                    'commission_rate' => $effectiveRate,
                    'commission' => $commission,
                    'net_earnings' => round($grossSales - $commission, 2),
                    'paid_amount' => round((float) ($row->paid_amount ?? 0), 2),
                    'pending_amount' => round((float) ($row->pending_amount ?? 0), 2),
                    'payout_status' => $payoutStatus,
                    'last_payout_at' => $lastPayouts->get($restaurant->id),
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Restaurant earnings fetched successfully.',
            'data' => [
                'commission_rate' => $commissionRate,
                'start_date' => $startDate?->toDateString(),
                'restaurants' => $restaurants,
            ],
        ]);
    }

    /**
     * Wallet transactions and commission history for one restaurant
     */
    public function payouts($id)
    {
        $restaurant = Restaurant::find($id);

        if (!$restaurant) {
            return response()->json([
                'message' => 'Restaurant not found.',
            ], 404);
        }

        $transactions = RestaurantWalletTransaction::query()
            ->where('restaurant_id', $restaurant->id)
            ->latest()
            ->limit(50)
            ->get([
                'id',
                'type',
                'amount',
                'balance_after',
                'title',
                'reference_type',
                'reference_id',
                'created_at',
            ]);

        // Each distinct rate applied to credited orders, newest first
        $commissionHistory = RestaurantOrder::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('earning_status', 'credited')
            ->whereNotNull('commission_percentage')
            ->groupBy('commission_percentage')
            ->select([
                'commission_percentage',
                DB::raw('MIN(earning_credited_at) as first_applied_at'),
                DB::raw('MAX(earning_credited_at) as last_applied_at'),
            ])
            ->orderByDesc('last_applied_at')
            ->get()
            ->map(fn ($row) => [
                'rate' => (float) $row->commission_percentage,
                'first_applied_at' => $row->first_applied_at,
                'last_applied_at' => $row->last_applied_at,
            ]);

        return response()->json([
            'message' => 'Restaurant payouts fetched successfully.',
            'data' => [
                'current_commission_rate' => $restaurant->commissionRate(),
                'transactions' => $transactions,
                'commission_history' => $commissionHistory,
            ],
        ]);
    }

    private function rangeStart(string $range): ?Carbon
    {
        return match ($range) {
            'all' => null,
            '7d' => now()->subDays(7)->startOfDay(),
            '90d' => now()->subDays(90)->startOfDay(),
            'year' => now()->startOfYear(),
            default => now()->subDays(30)->startOfDay(),
        };
    }
}
