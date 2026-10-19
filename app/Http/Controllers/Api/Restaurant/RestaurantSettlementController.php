<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantOrder;
use App\Models\RestaurantWalletTransaction;
use App\Models\RestaurantWeeklySettlement;
use App\Services\Restaurant\RestaurantSettlementService;
use App\Support\SettlementSchedule;
use Illuminate\Http\Request;

/**
 * Restaurant app: weekly (Monday to Monday) settlement history and the
 * credit / debit entries behind each payout.
 */
class RestaurantSettlementController extends Controller
{
    public function __construct(
        protected RestaurantSettlementService $settlementService
    ) {
    }

    /**
     * Settlement weeks, newest first, plus the running week
     */
    public function index(Request $request)
    {
        $restaurant = $request->user();

        $settlements = RestaurantWeeklySettlement::where('restaurant_id', $restaurant->id)
            ->latest('week_start')
            ->paginate(min((int) $request->query('per_page', 15), 50));

        $current = $this->settlementService->cycleFor();
        $currentRow = RestaurantWeeklySettlement::where('restaurant_id', $restaurant->id)
            ->whereDate('week_start', $current['week_start'])
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Settlements fetched successfully.',
            'data' => [
                'current_cycle' => [
                    'week_start' => $current['week_start']->toDateString(),
                    'week_end' => $current['week_end']->toDateString(),
                    'payout_at' => SettlementSchedule::payoutAt($current['payout_date'])->toDateTimeString(),
                    'orders_count' => (int) ($currentRow->orders_count ?? 0),
                    'earnings' => (float) ($currentRow->total_amount ?? 0),
                ],
                'wallet_balance' => (float) $restaurant->wallet_balance,
                'upi_id' => $this->settlementService->payoutUpi($restaurant),
                'how_it_works' => $this->howItWorks($restaurant),
                'settlements' => collect($settlements->items())->map(fn ($row) => $this->transform($row)),
                'pagination' => [
                    'current_page' => $settlements->currentPage(),
                    'last_page' => $settlements->lastPage(),
                    'per_page' => $settlements->perPage(),
                    'total' => $settlements->total(),
                ],
            ],
        ]);
    }

    /**
     * One week: every credit / debit entry and each order's calculation
     */
    public function show(Request $request, $id)
    {
        $restaurant = $request->user();

        $settlement = RestaurantWeeklySettlement::where('restaurant_id', $restaurant->id)->find($id);

        if (!$settlement) {
            return response()->json([
                'success' => false,
                'message' => 'Settlement not found.',
            ], 404);
        }

        $transactions = $settlement->transactions()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'type', 'title', 'amount', 'balance_after', 'description', 'reference_type', 'reference_id', 'created_at']);

        return response()->json([
            'success' => true,
            'message' => 'Settlement fetched successfully.',
            'data' => [
                'settlement' => $this->transform($settlement),
                'summary' => [
                    'credits' => round((float) $transactions->where('type', 'credit')->sum('amount'), 2),
                    'debits' => round((float) $transactions->where('type', 'debit')->sum('amount'), 2),
                ],
                'transactions' => $transactions,
                'orders' => $this->orders($transactions),
            ],
        ]);
    }

    /**
     * Full wallet credit / debit history with filters
     */
    public function walletHistory(Request $request)
    {
        $validated = $request->validate([
            'type' => ['nullable', 'in:credit,debit'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'settlement_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $transactions = RestaurantWalletTransaction::where('restaurant_id', $request->user()->id)
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->when($validated['settlement_id'] ?? null, fn ($query, $id) => $query->where('settlement_id', $id))
            ->latest()
            ->latest('id')
            ->paginate($validated['per_page'] ?? 20, [
                'id', 'settlement_id', 'type', 'title', 'amount', 'balance_after',
                'description', 'reference_type', 'reference_id', 'created_at',
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Wallet history fetched successfully.',
            'data' => [
                'transactions' => $transactions->items(),
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                ],
            ],
        ]);
    }

    /**
     * Per-order calculation for the order credits / reversals in a week
     */
    private function orders($transactions): array
    {
        $orderIds = $transactions
            ->whereIn('reference_type', ['restaurant_order', 'restaurant_order_reversal'])
            ->pluck('reference_id')
            ->unique();

        if ($orderIds->isEmpty()) {
            return [];
        }

        $orders = RestaurantOrder::whereIn('id', $orderIds)
            ->get(['id', 'order_number', 'subtotal', 'packing_charge', 'commission_percentage', 'commission_amount', 'restaurant_earning', 'earning_status', 'delivered_at'])
            ->keyBy('id');

        return $transactions
            ->whereIn('reference_type', ['restaurant_order', 'restaurant_order_reversal'])
            ->map(function ($entry) use ($orders) {
                $order = $orders->get($entry->reference_id);

                return [
                    'order_id' => $entry->reference_id,
                    'order_number' => $order?->order_number,
                    'entry' => $entry->type === 'credit' ? 'credited' : 'reversed',
                    'subtotal' => (float) ($order->subtotal ?? 0),
                    'packing_charge' => (float) ($order->packing_charge ?? 0),
                    'commission_rate' => (float) ($order->commission_percentage ?? 0),
                    'commission' => (float) ($order->commission_amount ?? 0),
                    'earning' => (float) ($order->restaurant_earning ?? 0),
                    'delivered_at' => $order?->delivered_at,
                    'at' => $entry->created_at,
                ];
            })
            ->values()
            ->all();
    }

    private function transform(RestaurantWeeklySettlement $settlement): array
    {
        $payoutAt = SettlementSchedule::payoutAt($settlement->payout_date);

        return [
            'id' => $settlement->id,
            'code' => 'RSS-' . $settlement->id,
            'period' => $this->settlementService->periodLabel($settlement),
            'week_start' => $settlement->week_start->toDateString(),
            'week_end' => $settlement->week_end->toDateString(),
            'payout_at' => $payoutAt->toDateTimeString(),
            'cycle_closed' => today()->gt($settlement->week_end),
            'orders_count' => $settlement->orders_count,
            'gross_sales' => (float) $settlement->gross_sales,
            'commission' => (float) $settlement->commission,
            'packing_charges' => (float) ($settlement->packing_charges ?? 0),
            'earnings' => (float) $settlement->total_amount,
            'opening_balance' => $settlement->opening_balance !== null ? (float) $settlement->opening_balance : null,
            'total_credit' => $settlement->total_credit !== null ? (float) $settlement->total_credit : null,
            'total_debit' => $settlement->total_debit !== null ? (float) $settlement->total_debit : null,
            'closing_balance' => $settlement->closing_balance !== null ? (float) $settlement->closing_balance : null,
            'payout_amount' => (float) $settlement->payout_amount,
            'status' => $settlement->status,
            'payout_reference' => $settlement->payout_reference,
            'processed_at' => $settlement->processed_at,
            'remarks' => $settlement->remarks,
        ];
    }

    private function howItWorks($restaurant): array
    {
        $rate = $restaurant->commissionRate();

        return [
            'commission_rate' => $rate,
            'steps' => [
                'Each order earns: food subtotal − ' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '% VegGo commission + packing charge.',
                'The earning is credited to your wallet when the order is delivered.',
                'If a delivered order is cancelled, its earning is debited back.',
                'Week runs Monday 00:00 to Sunday 23:59. Your wallet balance at the cut-off is paid out on Monday at '
                    . date('h:i A', strtotime(config('settlement.payout_time', '09:00'))) . ' to your UPI ID.',
                'If no UPI ID is added in documents the payout is skipped and carried to the next week.',
            ],
        ];
    }
}
