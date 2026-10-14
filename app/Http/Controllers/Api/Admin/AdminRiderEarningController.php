<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use App\Models\Rider;
use App\Models\RiderWeeklySettlement;
use App\Models\RiderEarning;
use App\Models\RiderNotification;
use App\Models\RiderCodSettlement;
use App\Services\Rider\RiderSettlementService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminRiderEarningController extends Controller
{
    public function __construct(
        protected RiderSettlementService $settlementService
    ) {
    }

    /**
     * Rider earnings list (deliveries, delivery earnings, incentives,
     * adjustments, paid amount, payout status)
     *
     * Figures follow RiderOrderService: each delivered order writes a
     * rider_earnings row and adds to the rider's daily settlement, which
     * RiderAutoPayoutService pays out. Paid = successful settlements.
     *
     * range: 7d | 30d | 90d | year
     */
    public function index(Request $request)
    {
        $startDate = $this->rangeStart($request->query('range', '7d'));

        $earnings = RiderEarning::query()
            ->where('created_at', '>=', $startDate)
            ->groupBy('rider_id')
            ->select([
                'rider_id',
                DB::raw("SUM(CASE WHEN type = 'delivery' THEN amount ELSE 0 END) as delivery_earnings"),
                DB::raw("SUM(CASE WHEN type IN ('bonus', 'incentive') THEN amount ELSE 0 END) as incentives"),
                DB::raw("SUM(CASE WHEN type = 'adjustment' THEN amount ELSE 0 END) as adjustments"),
            ])
            ->get()
            ->keyBy('rider_id');

        $deliveries = DeliveryOrder::query()
            ->where('status', 'delivered')
            ->whereNotNull('rider_id')
            ->where('delivered_at', '>=', $startDate)
            ->groupBy('rider_id')
            ->select('rider_id', DB::raw('COUNT(*) as deliveries'))
            ->pluck('deliveries', 'rider_id');

        $settlements = RiderWeeklySettlement::query()
            ->whereDate('week_end', '>=', $startDate)
            ->groupBy('rider_id')
            ->select([
                'rider_id',
                DB::raw("SUM(CASE WHEN status = 'successful' THEN total_amount ELSE 0 END) as paid_amount"),
                DB::raw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_count"),
            ])
            ->get()
            ->keyBy('rider_id');

        $activeRiderIds = $earnings->keys()
            ->merge($deliveries->keys())
            ->merge($settlements->keys())
            ->unique();

        $riders = Rider::query()
            ->where(function ($query) use ($activeRiderIds) {
                $query->where('status', 'approved')
                    ->orWhereIn('id', $activeRiderIds);
            })
            ->with('city:id,name')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'phone',
                'email',
                'city_id',
                'status',
                'wallet_balance',
                'created_at',
            ])
            ->map(function ($rider) use ($earnings, $deliveries, $settlements) {
                $earning = $earnings->get($rider->id);
                $settlement = $settlements->get($rider->id);

                $deliveryEarnings = round((float) ($earning->delivery_earnings ?? 0), 2);
                $incentives = round((float) ($earning->incentives ?? 0), 2);
                $adjustments = round((float) ($earning->adjustments ?? 0), 2);
                $paid = round((float) ($settlement->paid_amount ?? 0), 2);

                $total = $deliveryEarnings + $incentives + $adjustments;
                $pending = max(round($total - $paid, 2), 0);

                $payoutStatus = match (true) {
                    (int) ($settlement->processing_count ?? 0) > 0 => 'processing',
                    $pending > 0 => 'pending',
                    $paid > 0 => 'paid',
                    default => 'pending',
                };

                return [
                    'id' => $rider->id,
                    'name' => $rider->name,
                    'phone' => $rider->phone,
                    'email' => $rider->email,
                    'city' => $rider->city?->name,
                    'account_status' => $rider->status,
                    'wallet_balance' => (float) $rider->wallet_balance,
                    'joined_at' => $rider->created_at,
                    'deliveries' => (int) ($deliveries->get($rider->id) ?? 0),
                    'delivery_earnings' => $deliveryEarnings,
                    'incentives' => $incentives,
                    'adjustments' => $adjustments,
                    'paid_amount' => $paid,
                    'pending_amount' => $pending,
                    'payout_status' => $payoutStatus,
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Rider earnings fetched successfully.',
            'data' => [
                'start_date' => $startDate->toDateString(),
                'active_riders' => $riders
                    ->filter(fn ($row) => $row['deliveries'] > 0
                        || $row['delivery_earnings'] + $row['incentives'] + $row['adjustments'] != 0)
                    ->count(),
                'cycle' => $this->cycleSummary($startDate),
                'riders' => $riders,
            ],
        ]);
    }

    /**
     * Weekly settlement (payout) history for one rider
     */
    public function payouts($id)
    {
        $rider = Rider::find($id);

        if (!$rider) {
            return response()->json([
                'message' => 'Rider not found.',
            ], 404);
        }

        $settlements = RiderWeeklySettlement::query()
            ->where('rider_id', $rider->id)
            ->latest('week_start')
            ->limit(50)
            ->get([
                'id',
                'week_start',
                'week_end',
                'payout_date',
                'total_amount',
                'opening_balance',
                'total_credit',
                'total_debit',
                'closing_balance',
                'cod_deducted',
                'payout_amount',
                'status',
                'payout_reference',
                'processed_at',
                'remarks',
            ]);

        return response()->json([
            'message' => 'Rider payouts fetched successfully.',
            'data' => [
                'wallet_balance' => (float) $rider->wallet_balance,
                'pending_cod' => (float) $rider->pending_cod,
                'settlements' => $settlements,
            ],
        ]);
    }

    /**
     * One weekly settlement with every credit / debit entry and COD settlement linked to it
     */
    public function settlementDetail($id, $settlementId)
    {
        $settlement = RiderWeeklySettlement::query()
            ->where('rider_id', $id)
            ->find($settlementId);

        if (!$settlement) {
            return response()->json([
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
            'message' => 'Settlement fetched successfully.',
            'data' => [
                'settlement' => $settlement,
                'summary' => [
                    'credits' => (float) $transactions->where('type', 'credit')->sum('amount'),
                    'debits' => (float) $transactions->where('type', 'debit')->sum('amount'),
                ],
                'transactions' => $transactions,
                'cod_settlements' => $settlement->codSettlements()->latest()->get(),
            ],
        ]);
    }

    /**
     * Record COD the rider paid to admin in cash (wallet could not cover it)
     */
    public function settleCod(Request $request, $id)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        if (!Rider::whereKey($id)->exists()) {
            return response()->json([
                'message' => 'Rider not found.',
            ], 404);
        }

        $amount = round((float) $validated['amount'], 2);

        $result = DB::transaction(function () use ($request, $id, $amount, $validated) {
            $rider = Rider::lockForUpdate()->find($id);

            if ($amount > (float) $rider->pending_cod) {
                return null;
            }

            $this->settlementService->settleCod(
                $rider,
                $amount,
                'cash',
                'admin',
                null,
                $request->user(),
                $validated['remarks'] ?? 'COD paid to admin in cash.',
            );

            return $rider;
        });

        if (!$result) {
            return response()->json([
                'message' => 'Amount is more than the rider\'s pending COD.',
            ], 422);
        }

        return response()->json([
            'message' => 'COD settlement recorded successfully.',
            'data' => [
                'pending_cod' => (float) $result->pending_cod,
                'wallet_balance' => (float) $result->wallet_balance,
            ],
        ]);
    }

    /**
     * COD settlement history for one rider
     */
    public function codSettlements($id)
    {
        $rider = Rider::find($id);

        if (!$rider) {
            return response()->json([
                'message' => 'Rider not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'COD settlements fetched successfully.',
            'data' => [
                'pending_cod' => (float) $rider->pending_cod,
                'settlements' => RiderCodSettlement::where('rider_id', $rider->id)
                    ->latest()
                    ->limit(100)
                    ->get(),
            ],
        ]);
    }

    /**
     * Add or deduct an amount from the rider's earnings.
     *
     * Mirrors a delivery credit: earning row + wallet + this week's settlement,
     * so the adjustment is included in the next automatic payout.
     */
    public function adjust(Request $request, $id)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['add', 'deduct'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $rider = Rider::find($id);

        if (!$rider) {
            return response()->json([
                'message' => 'Rider not found.',
            ], 404);
        }

        $amount = round((float) $validated['amount'], 2);
        $signedAmount = $validated['type'] === 'deduct' ? -$amount : $amount;

        DB::transaction(function () use ($rider, $amount, $signedAmount, $validated) {
            $title = $signedAmount > 0 ? 'Earnings Adjustment (Added)' : 'Earnings Adjustment (Deducted)';
            $description = $validated['description'] ?? 'Adjustment by admin';

            RiderEarning::create([
                'rider_id' => $rider->id,
                'delivery_order_id' => null,
                'type' => 'adjustment',
                'amount' => $signedAmount,
                'title' => $title,
                'description' => $description,
            ]);

            $settlement = $this->settlementService->settlementFor($rider);

            $this->settlementService->record(
                $rider,
                $signedAmount > 0 ? 'credit' : 'debit',
                $amount,
                $title,
                $description,
                'adjustment',
                $settlement->id,
                $settlement,
                isEarning: true,
            );
        });

        return response()->json([
            'message' => 'Rider earnings adjusted successfully.',
            'data' => [
                'wallet_balance' => (float) $rider->fresh()->wallet_balance,
            ],
        ]);
    }

    /**
     * Change the payout status of the rider's unpaid settlements in the range.
     *
     * pending    -> back in the automatic payout queue
     * processing -> held (RiderAutoPayoutService only picks up pending ones)
     * paid       -> paid manually: wallet debited and settlement marked successful
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'processing', 'paid'])],
            'range' => ['nullable', Rule::in(['7d', '30d', '90d', 'year'])],
        ]);

        $rider = Rider::find($id);

        if (!$rider) {
            return response()->json([
                'message' => 'Rider not found.',
            ], 404);
        }

        $startDate = $this->rangeStart($validated['range'] ?? '7d');

        $settlements = RiderWeeklySettlement::query()
            ->where('rider_id', $rider->id)
            ->whereDate('week_end', '>=', $startDate)
            ->where('status', '!=', 'successful')
            ->orderBy('week_start')
            ->get();

        if ($settlements->isEmpty()) {
            return response()->json([
                'message' => 'No unpaid settlements found for this rider in the selected range.',
            ], 422);
        }

        DB::transaction(function () use ($settlements, $validated) {
            foreach ($settlements as $settlement) {
                if ($validated['status'] !== 'paid') {
                    $settlement->update([
                        'status' => $validated['status'],
                        'remarks' => 'Status set to ' . $validated['status'] . ' by admin.',
                    ]);

                    continue;
                }

                if ($settlement->refresh()->status === 'successful') {
                    continue;
                }

                // Oldest first: each pays out the wallet balance that belongs to its cycle
                $this->settlementService->payout($settlement, manual: true);
            }
        });

        return response()->json([
            'message' => 'Rider payout status updated successfully.',
        ]);
    }

    /**
     * Send an in-app notification to the rider
     */
    public function contact(Request $request, $id)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $rider = Rider::find($id);

        if (!$rider) {
            return response()->json([
                'message' => 'Rider not found.',
            ], 404);
        }

        RiderNotification::create([
            'rider_id' => $rider->id,
            'title' => 'Message from VegGo',
            'message' => $validated['message'],
            'type' => 'wallet',
        ]);

        return response()->json([
            'message' => 'Message sent to rider.',
        ]);
    }

    /**
     * Payout cycle across all riders for the range: overall status,
     * share already paid out and the next scheduled payout date.
     */
    private function cycleSummary(Carbon $startDate): array
    {
        $cycle = RiderWeeklySettlement::query()
            ->whereDate('week_end', '>=', $startDate)
            ->select([
                DB::raw('SUM(total_amount) as total_amount'),
                DB::raw("SUM(CASE WHEN status = 'successful' THEN total_amount ELSE 0 END) as paid_amount"),
                DB::raw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_count"),
                DB::raw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count"),
            ])
            ->first();

        $total = (float) ($cycle->total_amount ?? 0);
        $paid = (float) ($cycle->paid_amount ?? 0);

        $status = match (true) {
            (int) ($cycle->processing_count ?? 0) > 0 => 'processing',
            (int) ($cycle->pending_count ?? 0) > 0 => 'pending',
            (int) ($cycle->failed_count ?? 0) > 0 => 'failed',
            $total > 0 => 'completed',
            default => 'idle',
        };

        $nextPayoutDate = RiderWeeklySettlement::query()
            ->where('status', 'pending')
            ->min('payout_date');

        return [
            'status' => $status,
            'progress' => $total > 0 ? round(min($paid / $total, 1) * 100) : 0,
            'failed_count' => (int) ($cycle->failed_count ?? 0),
            'next_payout_date' => $nextPayoutDate
                ? Carbon::parse($nextPayoutDate)->toDateString()
                : null,
        ];
    }

    private function rangeStart(string $range): Carbon
    {
        return match ($range) {
            '30d' => now()->subDays(30)->startOfDay(),
            '90d' => now()->subDays(90)->startOfDay(),
            'year' => now()->startOfYear(),
            default => now()->subDays(7)->startOfDay(),
        };
    }
}
