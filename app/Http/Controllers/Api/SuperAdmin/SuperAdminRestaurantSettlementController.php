<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\ChangeActivityEvent;
use App\Models\RestaurantNotification;
use App\Models\RestaurantOrder;
use App\Models\RestaurantWeeklySettlement;
use App\Services\Restaurant\RestaurantAutoPayoutService;
use App\Services\Restaurant\RestaurantSettlementService;
use App\Support\SettlementSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Settlements page, Restaurants type: weekly (Monday to Sunday) restaurant
 * settlements, paid Monday morning 09:00 AM.
 *
 * Tabs map to RestaurantWeeklySettlement.status:
 *   pending -> Pending | processing -> On Hold | successful -> Completed | failed -> Failed
 */
class SuperAdminRestaurantSettlementController extends Controller
{
    private const PER_PAGE = 10;

    private const STATUSES = ['pending', 'processing', 'successful', 'failed'];

    public function __construct(
        protected RestaurantSettlementService $settlementService
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

        $summary = RestaurantWeeklySettlement::query()
            ->when($period, fn ($query) => $query->whereDate('week_start', $period))
            ->groupBy('status')
            ->select([
                'status',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total_amount) as earnings'),
                DB::raw('SUM(payout_amount) as paid'),
            ])
            ->get()
            ->keyBy('status');

        $settlements = RestaurantWeeklySettlement::query()
            ->with('restaurant:id,restaurant_name,mobile,wallet_balance')
            ->where('status', $status)
            ->when($period, fn ($query) => $query->whereDate('week_start', $period))
            ->when($validated['search'] ?? null, function ($query, $search) {
                $id = (int) preg_replace('/\D/', '', $search);

                $query->where(function ($query) use ($search, $id) {
                    $query->whereHas('restaurant', fn ($restaurant) => $restaurant
                        ->where('restaurant_name', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%"));

                    if ($id > 0) {
                        $query->orWhere('id', $id)->orWhere('restaurant_id', $id);
                    }
                });
            })
            ->latest('week_start')
            ->latest('id')
            ->paginate(self::PER_PAGE);

        $periods = RestaurantWeeklySettlement::query()
            ->select('week_start', 'week_end')
            ->distinct()
            ->orderByDesc('week_start')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'value' => $row->week_start->toDateString(),
                'label' => $this->settlementService->periodLabel($row),
            ]);

        $current = $this->settlementService->cycleFor();

        return response()->json([
            'message' => 'Settlements fetched successfully.',
            'data' => [
                'summary' => collect(self::STATUSES)->mapWithKeys(function ($key) use ($summary) {
                    $row = $summary->get($key);

                    return [$key => [
                        'count' => (int) ($row->count ?? 0),
                        'amount' => round((float) ($key === 'successful' ? ($row->paid ?? 0) : ($row->earnings ?? 0)), 2),
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
        $settlement = RestaurantWeeklySettlement::with('restaurant.address')->find($id);

        if (!$settlement) {
            return response()->json(['message' => 'Settlement not found.'], 404);
        }

        $restaurant = $settlement->restaurant;

        $transactions = $settlement->transactions()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'type', 'title', 'amount', 'balance_after', 'description', 'reference_type', 'reference_id', 'created_at']);

        $orderIds = $transactions
            ->whereIn('reference_type', ['restaurant_order', 'restaurant_order_reversal'])
            ->pluck('reference_id')
            ->unique();

        $orders = $orderIds->isEmpty() ? collect() : RestaurantOrder::whereIn('id', $orderIds)
            ->get(['id', 'order_number', 'subtotal', 'packing_charge', 'commission_percentage', 'commission_amount', 'restaurant_earning', 'earning_status', 'delivered_at']);

        return response()->json([
            'message' => 'Settlement fetched successfully.',
            'data' => [
                'settlement' => $this->transform($settlement),
                'restaurant' => $restaurant ? [
                    'id' => $restaurant->id,
                    'code' => 'REST-' . $restaurant->id,
                    'name' => $restaurant->restaurant_name,
                    'owner_name' => $restaurant->owner_name,
                    'phone' => $restaurant->mobile,
                    'email' => $restaurant->email,
                    'address' => $restaurant->address?->complete_address ?: $restaurant->address?->full_address,
                    'status' => $restaurant->status,
                    'wallet_balance' => (float) $restaurant->wallet_balance,
                    'commission_rate' => $restaurant->commissionRate(),
                    'upi_id' => $this->settlementService->payoutUpi($restaurant),
                ] : null,
                'summary' => [
                    'credits' => round((float) $transactions->where('type', 'credit')->sum('amount'), 2),
                    'debits' => round((float) $transactions->where('type', 'debit')->sum('amount'), 2),
                ],
                'transactions' => $transactions,
                'orders' => $orders->values(),
            ],
        ]);
    }

    /**
     * Run the restaurant payout now for every cycle that is due
     */
    public function runBatch(RestaurantAutoPayoutService $payoutService)
    {
        $before = RestaurantWeeklySettlement::where('status', 'pending')
            ->whereDate('payout_date', '<=', SettlementSchedule::dueDate())
            ->count();

        $processed = $payoutService->processDueSettlements();

        return response()->json([
            'message' => $before === 0
                ? 'No restaurant cycles are due yet. Restaurants are paid on Tuesday from ' . config('settlement.payout_time') . ' after the Monday cut-off.'
                : "Settlement batch finished: {$processed} of {$before} paid.",
            'data' => [
                'due' => $before,
                'processed' => $processed,
            ],
        ]);
    }

    public function markPaid($id)
    {
        return $this->act($id, function (RestaurantWeeklySettlement $settlement) {
            if ($settlement->status === 'successful') {
                return 'This settlement is already completed.';
            }

            $this->settlementService->payout($settlement, manual: true);

            return null;
        }, 'Settlement marked as paid.');
    }

    public function retry($id)
    {
        return $this->act($id, function (RestaurantWeeklySettlement $settlement) {
            if ($settlement->status !== 'failed') {
                return 'Only failed settlements can be retried.';
            }

            $settlement = $this->settlementService->payout($settlement);

            return $settlement->status === 'failed' ? $settlement->remarks : null;
        }, 'Payout retried successfully.');
    }

    public function updateHold(Request $request, $id)
    {
        $validated = $request->validate([
            'hold' => ['required', 'boolean'],
        ]);

        $hold = (bool) $validated['hold'];

        return $this->act($id, function (RestaurantWeeklySettlement $settlement) use ($hold) {
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

    public function contact(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $settlement = RestaurantWeeklySettlement::find($id);

        if (!$settlement) {
            return response()->json(['message' => 'Settlement not found.'], 404);
        }

        RestaurantNotification::create([
            'restaurant_id' => $settlement->restaurant_id,
            'type' => 'payment',
            'title' => $validated['title'] ?? 'Message from VegGo',
            'message' => $validated['message'],
            'reference_type' => 'restaurant_settlement',
            'reference_id' => $settlement->id,
            'is_read' => false,
        ]);

        return response()->json(['message' => 'Message sent to restaurant.']);
    }

    private function act($id, callable $action, string $successMessage)
    {
        $error = DB::transaction(function () use ($id, $action) {
            $settlement = RestaurantWeeklySettlement::lockForUpdate()->find($id);

            if (!$settlement) {
                return 'Settlement not found.';
            }

            return $action($settlement);
        });

        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $settlement = RestaurantWeeklySettlement::with('restaurant:id,restaurant_name,mobile,wallet_balance')->find($id);

        return response()->json([
            'message' => $successMessage,
            'data' => ['settlement' => $this->transform($settlement)],
        ]);
    }

    private function transform(RestaurantWeeklySettlement $settlement): array
    {
        $restaurant = $settlement->restaurant;

        $estimatedPayout = $settlement->status === 'successful' || !$restaurant
            ? (float) $settlement->payout_amount
            : $this->settlementService->payableFor($settlement, $restaurant);

        return [
            'id' => $settlement->id,
            'code' => 'RSS-' . $settlement->id,
            'type' => 'restaurant',
            'restaurant_id' => $settlement->restaurant_id,
            'restaurant_code' => 'REST-' . $settlement->restaurant_id,
            'restaurant_name' => $restaurant?->restaurant_name ?? 'Deleted restaurant',
            'restaurant_phone' => $restaurant?->mobile,
            'period' => $this->settlementService->periodLabel($settlement),
            'week_start' => $settlement->week_start->toDateString(),
            'week_end' => $settlement->week_end->toDateString(),
            'payout_date' => $settlement->payout_date->toDateString(),
            'payout_at' => SettlementSchedule::payoutAt($settlement->payout_date)->toDateTimeString(),
            'payout_due' => SettlementSchedule::isDue($settlement->payout_date),
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
            'estimated_payout' => $estimatedPayout,
            'status' => $settlement->status,
            'payout_reference' => $settlement->payout_reference,
            'processed_at' => $settlement->processed_at,
            'remarks' => $settlement->remarks,
            'created_at' => $settlement->created_at,
            'updated_at' => $settlement->updated_at,
        ];
    }

    private function activity(): array
    {
        $system = RestaurantWeeklySettlement::query()
            ->with('restaurant:id,restaurant_name')
            ->whereIn('status', ['successful', 'failed'])
            ->latest('updated_at')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'at' => $row->updated_at,
                'title' => $row->status === 'successful'
                    ? 'Payout of ₹' . number_format((float) $row->payout_amount, 2) . ' completed for ' . ($row->restaurant?->restaurant_name ?? 'restaurant') . '.'
                    : 'Payout failed for ' . ($row->restaurant?->restaurant_name ?? 'restaurant') . '.',
                'actor' => $row->status === 'failed'
                    ? ($row->remarks ?: 'Payout failed')
                    : (str_starts_with((string) $row->payout_reference, 'MANUAL') ? 'Marked paid by admin' : 'System auto-payout'),
                'ref' => 'RSS-' . $row->id,
                'status' => $row->status,
            ]);

        // admin_activity_logs from now on; change_activity_events holds older actions
        $admin = collect([AdminActivityLog::query(), ChangeActivityEvent::query()])
            ->flatMap(fn ($query) => $query
                ->where('module', 'Settlements')
                ->where('path', 'like', '%/settlements/restaurants%')
                ->latest('id')
                ->limit(15)
                ->get())
            ->map(fn ($row) => [
                'at' => $row->created_at,
                'title' => match ($row->action) {
                    'Settlement Batch Run', 'Run Batch' => 'Restaurant settlement batch run manually.',
                    'Settlement Marked Paid', 'Mark Paid' => 'Settlement marked as paid.',
                    'Settlement Retry', 'Retry' => 'Failed payout retried.',
                    'Settlement Hold', 'Update Hold' => 'Settlement hold status changed.',
                    'Restaurant Contacted', 'Contact' => 'Restaurant contacted about settlement.',
                    default => $row->action . '.',
                },
                'actor' => $row->admin_name,
                'ref' => $row->target ? str_replace('#SET-', 'RSS-', $row->target) : 'BATCH',
                'status' => 'admin',
            ]);

        return $system->concat($admin)
            ->sortByDesc('at')
            ->take(20)
            ->values()
            ->all();
    }
}
