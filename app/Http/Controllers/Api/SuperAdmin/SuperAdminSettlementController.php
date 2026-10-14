<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ChangeActivityEvent;
use App\Models\Rider;
use App\Models\RiderBankDetail;
use App\Models\RiderNotification;
use App\Models\RiderUpiDetail;
use App\Models\RiderWeeklySettlement;
use App\Services\Rider\RiderAutoPayoutService;
use App\Services\Rider\RiderSettlementService;
use App\Support\SettlementSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Settlements page. Rider weekly (Wednesday to Wednesday) settlements only
 * for now; restaurant settlements will be added as a second type.
 *
 * Tabs map to RiderWeeklySettlement.status:
 *   pending -> Pending | processing -> On Hold | successful -> Completed | failed -> Failed
 */
class SuperAdminSettlementController extends Controller
{
    private const PER_PAGE = 10;

    private const STATUSES = ['pending', 'processing', 'successful', 'failed'];

    public function __construct(
        protected RiderSettlementService $settlementService
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'period' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $status = $validated['status'] ?? 'pending';
        $period = $validated['period'] ?? null;

        // Summary cards follow the period filter but not the tab / search
        $summary = RiderWeeklySettlement::query()
            ->when($period, fn ($query) => $query->whereDate('week_start', $period))
            ->groupBy('status')
            ->select([
                'status',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total_amount) as earnings'),
                DB::raw('SUM(payout_amount) as paid'),
                DB::raw('SUM(cod_deducted) as cod'),
            ])
            ->get()
            ->keyBy('status');

        $settlements = RiderWeeklySettlement::query()
            ->with('rider:id,name,phone,wallet_balance,pending_cod')
            ->where('status', $status)
            ->when($period, fn ($query) => $query->whereDate('week_start', $period))
            ->when($validated['search'] ?? null, function ($query, $search) {
                $id = (int) preg_replace('/\D/', '', $search);

                $query->where(function ($query) use ($search, $id) {
                    $query->whereHas('rider', fn ($rider) => $rider
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));

                    if ($id > 0) {
                        $query->orWhere('id', $id)->orWhere('rider_id', $id);
                    }
                });
            })
            ->latest('week_start')
            ->latest('id')
            ->paginate(self::PER_PAGE);

        $periods = RiderWeeklySettlement::query()
            ->select('week_start', 'week_end')
            ->distinct()
            ->orderByDesc('week_start')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'value' => $row->week_start->toDateString(),
                'label' => $this->periodLabel($row),
            ]);

        $current = $this->settlementService->cycleFor();

        return response()->json([
            'message' => 'Settlements fetched successfully.',
            'data' => [
                'summary' => collect(self::STATUSES)->mapWithKeys(function ($key) use ($summary) {
                    $row = $summary->get($key);

                    return [$key => [
                        'count' => (int) ($row->count ?? 0),
                        // Completed shows what was paid out, the rest the cycle earnings
                        'amount' => round((float) ($key === 'successful' ? ($row->paid ?? 0) : ($row->earnings ?? 0)), 2),
                        'cod_deducted' => round((float) ($row->cod ?? 0), 2),
                    ]];
                }),
                'current_cycle' => [
                    'week_start' => $current['week_start']->toDateString(),
                    'week_end' => $current['week_end']->toDateString(),
                    'payout_date' => $current['payout_date']->toDateString(),
                    'payout_at' => SettlementSchedule::payoutAt($current['payout_date'])->toDateTimeString(),
                ],
                'periods' => $periods,
                'settlements' => collect($settlements->items())->map(fn ($row) => $this->transform($row)),
                'pagination' => [
                    'current_page' => $settlements->currentPage(),
                    'last_page' => $settlements->lastPage(),
                    'per_page' => $settlements->perPage(),
                    'total' => $settlements->total(),
                ],
                'activity' => $this->activity(),
            ],
        ]);
    }

    public function show($id)
    {
        $settlement = RiderWeeklySettlement::with('rider.city:id,name')->find($id);

        if (!$settlement) {
            return response()->json(['message' => 'Settlement not found.'], 404);
        }

        $rider = $settlement->rider;

        $transactions = $settlement->transactions()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'type', 'title', 'amount', 'balance_after', 'description', 'reference_type', 'reference_id', 'created_at']);

        $upi = $rider ? RiderUpiDetail::where('rider_id', $rider->id)->latest()->first() : null;
        $bank = $rider ? RiderBankDetail::where('rider_id', $rider->id)->latest()->first() : null;

        return response()->json([
            'message' => 'Settlement fetched successfully.',
            'data' => [
                'settlement' => $this->transform($settlement),
                'rider' => $rider ? [
                    'id' => $rider->id,
                    'code' => 'RDR-' . $rider->id,
                    'name' => $rider->name,
                    'phone' => $rider->phone,
                    'email' => $rider->email,
                    'city' => $rider->city?->name,
                    'status' => $rider->status,
                    'wallet_balance' => (float) $rider->wallet_balance,
                    'pending_cod' => (float) $rider->pending_cod,
                ] : null,
                'payout_accounts' => [
                    'upi' => $upi ? [
                        'holder_name' => $upi->holder_name,
                        'upi_id' => $upi->upi_id,
                        'is_verified' => (bool) $upi->is_verified,
                    ] : null,
                    'bank' => $bank ? [
                        'holder_name' => $bank->account_holder_name,
                        'bank_name' => $bank->bank_name,
                        'account_ending' => substr((string) $bank->account_number, -4),
                        'ifsc_code' => $bank->ifsc_code,
                        'is_verified' => (bool) $bank->is_verified,
                    ] : null,
                ],
                'summary' => [
                    'credits' => round((float) $transactions->where('type', 'credit')->sum('amount'), 2),
                    'debits' => round((float) $transactions->where('type', 'debit')->sum('amount'), 2),
                ],
                'transactions' => $transactions,
                'cod_settlements' => $settlement->codSettlements()->latest()->get([
                    'id', 'amount', 'method', 'source', 'pending_cod_after', 'remarks', 'created_at',
                ]),
            ],
        ]);
    }

    /**
     * Run the automatic payout now for every closed cycle (same as the scheduler)
     */
    public function runBatch(RiderAutoPayoutService $payoutService)
    {
        $before = RiderWeeklySettlement::where('status', 'pending')
            ->whereDate('payout_date', '<=', SettlementSchedule::dueDate())
            ->count();

        $processed = $payoutService->processDueSettlements();

        $failed = RiderWeeklySettlement::where('status', 'failed')
            ->whereDate('updated_at', today())
            ->count();

        return response()->json([
            'message' => $before === 0
                ? 'No rider cycles are due yet. Riders are paid on Thursday from ' . config('settlement.payout_time') . ' after the Wednesday cut-off.'
                : "Settlement batch finished: {$processed} of {$before} paid.",
            'data' => [
                'due' => $before,
                'processed' => $processed,
                'failed_today' => $failed,
            ],
        ]);
    }

    /**
     * Admin paid the rider outside the app
     */
    public function markPaid($id)
    {
        return $this->act($id, function (RiderWeeklySettlement $settlement) {
            if ($settlement->status === 'successful') {
                return 'This settlement is already completed.';
            }

            $this->settlementService->payout($settlement, manual: true);

            return null;
        }, 'Settlement marked as paid.');
    }

    /**
     * Retry the automatic payout for a failed settlement
     */
    public function retry($id)
    {
        return $this->act($id, function (RiderWeeklySettlement $settlement) {
            if ($settlement->status !== 'failed') {
                return 'Only failed settlements can be retried.';
            }

            $settlement = $this->settlementService->payout($settlement);

            return $settlement->status === 'failed' ? $settlement->remarks : null;
        }, 'Payout retried successfully.');
    }

    /**
     * Hold (processing) or release (pending) a settlement
     */
    public function updateHold(Request $request, $id)
    {
        $validated = $request->validate([
            'hold' => ['required', 'boolean'],
        ]);

        $hold = (bool) $validated['hold'];

        return $this->act($id, function (RiderWeeklySettlement $settlement) use ($hold) {
            if ($hold && $settlement->status !== 'pending') {
                return 'Only pending settlements can be put on hold.';
            }

            if (!$hold && $settlement->status !== 'processing') {
                return 'Only settlements on hold can be released.';
            }

            $settlement->update([
                'status' => $hold ? 'processing' : 'pending',
                'remarks' => $hold ? 'Put on hold by admin.' : 'Released from hold by admin.',
            ]);

            return null;
        }, $hold ? 'Settlement put on hold.' : 'Settlement released to the payout queue.');
    }

    /**
     * Record COD the rider paid in cash (wallet could not cover it)
     */
    public function settleCod(Request $request, $id)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $settlement = RiderWeeklySettlement::find($id);

        if (!$settlement) {
            return response()->json(['message' => 'Settlement not found.'], 404);
        }

        $amount = round((float) $validated['amount'], 2);

        $error = DB::transaction(function () use ($request, $settlement, $amount, $validated) {
            $rider = Rider::lockForUpdate()->find($settlement->rider_id);

            if (!$rider) {
                return 'Rider not found.';
            }

            if ($amount > (float) $rider->pending_cod) {
                return 'Amount is more than the rider\'s pending COD (₹' . number_format((float) $rider->pending_cod, 2) . ').';
            }

            $this->settlementService->settleCod(
                $rider,
                $amount,
                'cash',
                'admin',
                $settlement,
                $request->user(),
                $validated['remarks'] ?? 'COD paid to admin in cash.',
            );

            return null;
        });

        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json(['message' => 'COD cash settlement recorded.']);
    }

    /**
     * In-app notification to the rider about this settlement
     */
    public function contact(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $settlement = RiderWeeklySettlement::find($id);

        if (!$settlement) {
            return response()->json(['message' => 'Settlement not found.'], 404);
        }

        RiderNotification::create([
            'rider_id' => $settlement->rider_id,
            'title' => $validated['title'] ?? 'Message from VegGo',
            'message' => $validated['message'],
            'type' => 'wallet',
        ]);

        return response()->json(['message' => 'Message sent to rider.']);
    }

    /**
     * Lock the settlement, run the action, return its error message or the success response
     */
    private function act($id, callable $action, string $successMessage)
    {
        $error = DB::transaction(function () use ($id, $action) {
            $settlement = RiderWeeklySettlement::lockForUpdate()->find($id);

            if (!$settlement) {
                return 'Settlement not found.';
            }

            return $action($settlement);
        });

        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $settlement = RiderWeeklySettlement::with('rider:id,name,phone,wallet_balance,pending_cod')->find($id);

        return response()->json([
            'message' => $successMessage,
            'data' => ['settlement' => $this->transform($settlement)],
        ]);
    }

    private function transform(RiderWeeklySettlement $settlement): array
    {
        $rider = $settlement->rider;
        $closed = today()->gt($settlement->week_end);

        // What the payout would be right now (completed ones show what was paid)
        $estimatedPayout = $settlement->status === 'successful' || !$rider
            ? (float) $settlement->payout_amount
            : $this->settlementService->payableFor($settlement, $rider);

        return [
            'id' => $settlement->id,
            'code' => 'RST-' . $settlement->id,
            'type' => 'rider',
            'rider_id' => $settlement->rider_id,
            'rider_code' => 'RDR-' . $settlement->rider_id,
            'rider_name' => $rider?->name ?? 'Deleted rider',
            'rider_phone' => $rider?->phone,
            'rider_pending_cod' => (float) ($rider?->pending_cod ?? 0),
            'period' => $this->periodLabel($settlement),
            'week_start' => $settlement->week_start->toDateString(),
            'week_end' => $settlement->week_end->toDateString(),
            'payout_date' => $settlement->payout_date->toDateString(),
            'payout_at' => SettlementSchedule::payoutAt($settlement->payout_date)->toDateTimeString(),
            'payout_due' => SettlementSchedule::isDue($settlement->payout_date),
            'cycle_closed' => $closed,
            'earnings' => (float) $settlement->total_amount,
            'opening_balance' => $settlement->opening_balance !== null ? (float) $settlement->opening_balance : null,
            'total_credit' => $settlement->total_credit !== null ? (float) $settlement->total_credit : null,
            'total_debit' => $settlement->total_debit !== null ? (float) $settlement->total_debit : null,
            'closing_balance' => $settlement->closing_balance !== null ? (float) $settlement->closing_balance : null,
            'cod_deducted' => (float) $settlement->cod_deducted,
            'payout_amount' => (float) $settlement->payout_amount,
            'estimated_payout' => $estimatedPayout,
            'status' => $settlement->status,
            'payout_reference' => $settlement->payout_reference,
            'processed_at' => $settlement->processed_at,
            'remarks' => $settlement->remarks,
            'created_at' => $settlement->created_at,
            'updated_at' => $settlement->updated_at,
        ];
    }

    private function periodLabel(RiderWeeklySettlement $settlement): string
    {
        return $settlement->week_start->format('d M') . ' – ' . $settlement->week_end->format('d M Y');
    }

    /**
     * Recent payout results (system) merged with admin actions on this page
     */
    private function activity(): array
    {
        $system = RiderWeeklySettlement::query()
            ->with('rider:id,name')
            ->whereIn('status', ['successful', 'failed'])
            ->latest('updated_at')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'at' => $row->updated_at,
                'title' => $row->status === 'successful'
                    ? 'Payout of ₹' . number_format((float) $row->payout_amount, 2) . ' completed for ' . ($row->rider?->name ?? 'rider') . '.'
                    : 'Payout failed for ' . ($row->rider?->name ?? 'rider') . '.',
                'actor' => $row->status === 'failed'
                    ? ($row->remarks ?: 'Payout failed')
                    : (str_starts_with((string) $row->payout_reference, 'MANUAL') ? 'Marked paid by admin' : 'System auto-payout'),
                'ref' => 'RST-' . $row->id,
                'status' => $row->status,
            ]);

        $admin = ChangeActivityEvent::query()
            ->where('module', 'Settlements')
            ->where('path', 'not like', '%/settlements/restaurants%')
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'at' => $row->created_at,
                'title' => match ($row->action) {
                    'Run Batch' => 'Settlement batch run manually.',
                    'Mark Paid' => 'Settlement marked as paid.',
                    'Retry' => 'Failed payout retried.',
                    'Update Hold' => 'Settlement hold status changed.',
                    'Settle Cod' => 'COD cash settlement recorded.',
                    'Contact' => 'Rider contacted about settlement.',
                    default => $row->action . '.',
                },
                'actor' => $row->admin_name,
                'ref' => $row->target ? str_replace('#SET-', 'RST-', $row->target) : 'BATCH',
                'status' => 'admin',
            ]);

        return $system->concat($admin)
            ->sortByDesc('at')
            ->take(20)
            ->values()
            ->all();
    }
}
