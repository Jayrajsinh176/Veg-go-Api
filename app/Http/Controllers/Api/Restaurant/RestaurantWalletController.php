<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantWalletTransaction;
use Illuminate\Http\Request;
use Carbon\Carbon;

class RestaurantWalletController extends Controller
{
    /**
     * Restaurant Wallet Summary
     */
    public function index(Request $request)
    {
        $restaurant = $request->user();

        $transactions = RestaurantWalletTransaction::where(
            'restaurant_id',
            $restaurant->id
        );

        // Total Balance
        $balance = $restaurant->wallet_balance;

        // Today's credited earnings
        $today = (clone $transactions)
            ->where('type', 'credit')
            ->whereDate('created_at', today())
            ->sum('amount');

        // This week's credited earnings
        $week = (clone $transactions)
            ->where('type', 'credit')
            ->whereBetween('created_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ])
            ->sum('amount');

        // This month's credited earnings
        $month = (clone $transactions)
            ->where('type', 'credit')
            ->whereBetween('created_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->sum('amount');

        // Recent transactions
        $recentTransactions = (clone $transactions)
            ->latest()
            ->limit(10)
            ->get([
                'id',
                'type',
                'amount',
                'balance_after',
                'title',
                'description',
                'reference_type',
                'reference_id',
                'created_at',
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Wallet details fetched successfully.',
            'data' => [
                'balance' => (float) $balance,

                'summary' => [
                    'today' => (float) $today,
                    'this_week' => (float) $week,
                    'this_month' => (float) $month,
                ],

                'recent_transactions' => $recentTransactions,
            ],
        ]);
    }

public function earningsTrend(Request $request)
{
    $restaurant = $request->user();

    $startOfWeek = now()->startOfWeek();
    $endOfWeek = now()->endOfWeek();

    $transactions = RestaurantWalletTransaction::where('restaurant_id', $restaurant->id)
        ->where('type', 'credit')
        ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
        ->get();

    $days = [];

    for ($date = $startOfWeek->copy(); $date <= $endOfWeek; $date->addDay()) {
        $amount = $transactions
            ->filter(function ($transaction) use ($date) {
                return $transaction->created_at->isSameDay($date);
            })
            ->sum('amount');

        $days[] = [
            'day' => $date->format('D'),
            'date' => $date->format('Y-m-d'),
            'amount' => (float) $amount,
        ];
    }

    return response()->json([
        'success' => true,
        'message' => 'Earnings trend fetched successfully.',
        'data' => [
            'period' => 'this_week',
            'days' => $days,
        ],
    ]);
}

}