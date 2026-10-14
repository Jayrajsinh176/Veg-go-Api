<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Models\RiderWeeklySettlement;
use App\Services\Rider\RiderSettlementService;
use Illuminate\Http\Request;

class RiderSettlementController extends Controller
{
    /**
     * Weekly (Wednesday to Wednesday) settlements, newest first
     */
    public function index(Request $request, RiderSettlementService $settlementService)
    {
        $rider = $request->user();

        $settlements = RiderWeeklySettlement::where('rider_id', $rider->id)
            ->latest('week_start')
            ->paginate(min((int) $request->query('per_page', 15), 50));

        $current = $settlementService->cycleFor();

        return response()->json([
            'success' => true,
            'data' => [
                'current_cycle' => [
                    'week_start' => $current['week_start']->toDateString(),
                    'week_end' => $current['week_end']->toDateString(),
                    'payout_date' => $current['payout_date']->toDateString(),
                ],
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
     * One cycle with every credit and debit entry linked to it
     */
    public function show(Request $request, $id)
    {
        $settlement = RiderWeeklySettlement::where('rider_id', $request->user()->id)
            ->find($id);

        if (!$settlement) {
            return response()->json([
                'success' => false,
                'message' => 'Settlement not found.',
            ], 404);
        }

        $transactions = $settlement->transactions()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id',
                'type',
                'title',
                'amount',
                'balance_after',
                'description',
                'reference_type',
                'reference_id',
                'created_at',
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'settlement' => $this->transform($settlement),
                'summary' => [
                    'credits' => (float) $transactions->where('type', 'credit')->sum('amount'),
                    'debits' => (float) $transactions->where('type', 'debit')->sum('amount'),
                ],
                'transactions' => $transactions,
            ],
        ]);
    }

    private function transform(RiderWeeklySettlement $settlement): array
    {
        return [
            'id' => $settlement->id,
            'week_start' => $settlement->week_start->toDateString(),
            'week_end' => $settlement->week_end->toDateString(),
            'payout_date' => $settlement->payout_date->toDateString(),
            'earnings' => (float) $settlement->total_amount,
            'opening_balance' => $settlement->opening_balance !== null ? (float) $settlement->opening_balance : null,
            'total_credit' => $settlement->total_credit !== null ? (float) $settlement->total_credit : null,
            'total_debit' => $settlement->total_debit !== null ? (float) $settlement->total_debit : null,
            'closing_balance' => $settlement->closing_balance !== null ? (float) $settlement->closing_balance : null,
            'cod_deducted' => (float) $settlement->cod_deducted,
            'payout_amount' => (float) $settlement->payout_amount,
            'status' => $settlement->status,
            'payout_reference' => $settlement->payout_reference,
            'processed_at' => $settlement->processed_at,
            'remarks' => $settlement->remarks,
        ];
    }
}
