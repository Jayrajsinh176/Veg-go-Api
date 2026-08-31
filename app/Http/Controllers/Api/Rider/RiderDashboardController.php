<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DeliveryOrder;
use App\Models\RiderKyc;
use App\Models\RiderEarning;
use App\Models\RiderWalletTransaction;
use App\Services\Rider\RiderShiftService;
use App\Models\RiderDailySettlement;
use Carbon\Carbon;

class RiderDashboardController extends Controller
{
    protected $shiftService;

public function __construct(RiderShiftService $shiftService)
{
    $this->shiftService = $shiftService;
}

    public function home(Request $request)
{
    $rider = $request->user();
    $kyc = RiderKyc::where('rider_id', $rider->id)->first();

$profileImage = $kyc && $kyc->selfie
    ? asset('storage/' . $kyc->selfie)
    : null;
$availableOrdersCount = 0;
$estimatedEarnings = 0;

    $availableOrdersCount = 0;

    // Today's statistics
$targetOrders = 20;

$completedOrders = DeliveryOrder::where('rider_id', $rider->id)
    ->where('status', 'delivered')
    ->whereDate('updated_at', today())
    ->count();

$remainingOrders = max(0, $targetOrders - $completedOrders);

$progressPercentage = $targetOrders > 0
    ? round(($completedOrders / $targetOrders) * 100)
    : 0;

$todayEarnings = RiderEarning::where('rider_id', $rider->id)
    ->whereDate('created_at', today())
    ->sum('amount');

$todayBonus = RiderEarning::where('rider_id', $rider->id)
    ->where('type', 'bonus')
    ->whereDate('created_at', today())
    ->sum('amount');

    $hasActiveOrder = DeliveryOrder::where('rider_id', $rider->id)
    ->whereIn('status', [
        'accepted',
        'arrived',
        'picked_up',
    ])
    ->exists();
   if (
    $rider->is_online &&
    !$hasActiveOrder &&
    $rider->latitude !== null &&
    $rider->longitude !== null
) {
        $latitude = (float) $rider->latitude;
        $longitude = (float) $rider->longitude;
        $radius = 10;

        $availableOrders = DeliveryOrder::query()
            ->select('delivery_orders.*')
            ->selectRaw(
                '(6371 * acos(
                    cos(radians(?))
                    * cos(radians(pickup_latitude))
                    * cos(
                        radians(pickup_longitude) - radians(?)
                    )
                    + sin(radians(?))
                    * sin(radians(pickup_latitude))
                )) AS pickup_distance_km',
                [
                    $latitude,
                    $longitude,
                    $latitude,
                ]
            )
            ->where('status', 'available')
            ->having('pickup_distance_km', '<=', $radius)
            ->get();

        $availableOrdersCount = $availableOrders->count();

        $estimatedEarnings = (float) $availableOrders->sum(
            'rider_earning'
        );
    }

    return response()->json([
        'success' => true,

'rider' => [
    'id' => $rider->id,
    'name' => $rider->name,
    'first_name' => $rider->first_name,
    'status' => $rider->status,
   'profile_image' => $kyc && $kyc->selfie
    ? asset('storage/' . $kyc->selfie)
    : null,
],

     'availability' => [
    'is_online' => (bool) $rider->is_online,
    'is_live' => $rider->last_location_at !== null,
    'last_online_at' => optional($rider->last_online_at)->toISOString(),
],

  'today' => [
    'target_orders' => $targetOrders,
    'completed_orders' => $completedOrders,
    'remaining_orders' => $remainingOrders,
    'progress_percentage' => $progressPercentage,
    'target_completed' => $completedOrders >= $targetOrders,
],
       'quick_stats' => [
    'today_earnings' => (float) $todayEarnings,
    'today_orders' => $completedOrders,
    'bonus' => (float) $todayBonus,
    'wallet_balance' => (float) $rider->wallet_balance,
],

      'available_orders' => [
    'count' => $availableOrdersCount,
    'estimated_earnings' => $estimatedEarnings,
    'search_radius_km' => 10,
],

        'weekly_performance' => [
            'percentage_faster' => 0,
            'message' => null,
        ],

        'unread_notifications' => 0,
    ]);
}

    public function updateAvailability(Request $request)
{
    $request->validate([
        'is_online' => [
            'required',
            'boolean',
        ],
    ]);

    $rider = $request->user();

    if ($rider->status !== 'approved') {
        return response()->json([
            'success' => false,
            'message' => 'Your rider account is not approved yet',
            'rider_status' => $rider->status,
        ], 403);
    }

    if (!$rider->onboarding_completed) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete onboarding first',
        ], 400);
    }

    $isOnline = $request->boolean('is_online');

    if (!$isOnline) {

    $hasActiveOrder = DeliveryOrder::where('rider_id', $rider->id)
        ->whereIn('status', [
            'accepted',
            'arrived',
            'picked_up',
        ])
        ->exists();

    if ($hasActiveOrder) {
        return response()->json([
            'success' => false,
            'message' => 'Complete your current order before going offline.',
        ], 400);
    }
}


  if ($isOnline) {

    $result = $this->shiftService->startShift($rider->id);

    if (!$result['success']) {
        return response()->json($result, 400);
    }

} else {

    $this->shiftService->completeShift($rider->id);
}

$rider->refresh();

    return response()->json([
        'success' => true,
        'message' => $isOnline
            ? 'You are now online'
            : 'You are now offline',
        'availability' => [
            'is_online' => (bool) $rider->is_online,
            'last_online_at' => optional(
                $rider->last_online_at
            )->toISOString(),
        ],
    ]);
}

    public function updateLiveLocation(Request $request)
{
    $validator = \Illuminate\Support\Facades\Validator::make(
        $request->all(),
        [
            'latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],
            'longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],
            'heading' => [
                'nullable',
                'numeric',
                'between:0,360',
            ],
            'speed' => [
                'nullable',
                'numeric',
                'min:0',
            ],
        ]
    );

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    if ($rider->status !== 'approved') {
        return response()->json([
            'success' => false,
            'message' => 'Your rider account is not approved',
        ], 403);
    }

    if (!$rider->is_online) {
        return response()->json([
            'success' => false,
            'message' => 'Please go online before updating location',
        ], 400);
    }

    $rider->update([
        'latitude' => $request->latitude,
        'longitude' => $request->longitude,
        'heading' => $request->heading,
        'speed' => $request->speed,
        'last_location_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Live location updated successfully',
        'location' => [
            'latitude' => (float) $rider->latitude,
            'longitude' => (float) $rider->longitude,
            'heading' => $rider->heading !== null
                ? (float) $rider->heading
                : null,
            'speed' => $rider->speed !== null
                ? (float) $rider->speed
                : null,
            'last_location_at' => $rider
                ->last_location_at
                ->toISOString(),
        ],
    ]);
}

    public function availableOrders(Request $request)
{
    $rider = $request->user();

    if ($rider->status !== 'approved') {
        return response()->json([
            'success' => false,
            'message' => 'Your rider account is not approved',
        ], 403);
    }

    if (!$rider->is_online) {
        return response()->json([
            'success' => false,
            'message' => 'Please go online to view available orders',
        ], 400);
    }

    $hasActiveOrder = DeliveryOrder::where('rider_id', $rider->id)
    ->whereIn('status', [
        'accepted',
        'arrived',
        'picked_up',
    ])
    ->exists();

if ($hasActiveOrder) {
    return response()->json([
        'success' => true,
        'search_radius_km' => 10,
        'count' => 0,
        'estimated_earnings' => 0,
        'orders' => [],
        'message' => 'Complete your current order to receive new orders.',
    ]);
}

    if (
        $rider->latitude === null ||
        $rider->longitude === null
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Please update your live location first',
        ], 400);
    }

    $latitude = (float) $rider->latitude;
    $longitude = (float) $rider->longitude;
    $radius = 10;

    $orders = DeliveryOrder::query()
        ->select('delivery_orders.*')
        ->selectRaw(
            '(6371 * acos(
                cos(radians(?))
                * cos(radians(pickup_latitude))
                * cos(radians(pickup_longitude) - radians(?))
                + sin(radians(?))
                * sin(radians(pickup_latitude))
            )) AS pickup_distance_km',
            [
                $latitude,
                $longitude,
                $latitude,
            ]
        )
        ->where('status', 'available')
        ->having('pickup_distance_km', '<=', $radius)
        ->orderBy('pickup_distance_km')
        ->get();
$formattedOrders = $orders->map(function ($order) use ($latitude, $longitude) {

    // Calculate drop distance (Rider -> Customer)
    $dropDistanceKm = 6371 * acos(
        cos(deg2rad($latitude))
        * cos(deg2rad($order->delivery_latitude))
        * cos(deg2rad($order->delivery_longitude) - deg2rad($longitude))
        + sin(deg2rad($latitude))
        * sin(deg2rad($order->delivery_latitude))
    );

    // Simple estimated time (3 minutes per km)
    $estimatedTime = ceil(
        ($order->pickup_distance_km + $dropDistanceKm) * 3
    );

    return [
        'id' => $order->id,
        'order_number' => $order->order_number,

        'restaurant_name' => $order->restaurant_name,
        'restaurant_address' => $order->restaurant_address,

        'customer_name' => $order->customer_name,
        'customer_phone' => $order->customer_phone,

        'delivery_address' => $order->delivery_address,

        'pickup_distance_km' => round((float)$order->pickup_distance_km, 2),
        'drop_distance_km' => round($dropDistanceKm, 2),

        'estimated_time_minutes' => $estimatedTime,
        
'payment_method' => $order->payment_method,

        'rider_earning' => (float)$order->rider_earning,
    ];
});

    return response()->json([
        'success' => true,
        'search_radius_km' => $radius,
        'count' => $formattedOrders->count(),
        'estimated_earnings' => $formattedOrders->sum(
            'rider_earning'
        ),
        'orders' => $formattedOrders,
    ]);
}

    public function dashboardStats(Request $request)
{
    $rider = $request->user();

    $todayStart = Carbon::today();
    $todayEnd = Carbon::tomorrow();

    $weekStart = Carbon::now()->startOfWeek();
    $weekEnd = Carbon::now()->endOfWeek();

    $monthStart = Carbon::now()->startOfMonth();
    $monthEnd = Carbon::now()->endOfMonth();

    $todayEarnings = RiderEarning::where('rider_id', $rider->id)
        ->whereBetween('created_at', [$todayStart, $todayEnd])
        ->sum('amount');

    $todayBonus = RiderEarning::where('rider_id', $rider->id)
        ->where('type', 'bonus')
        ->whereBetween('created_at', [$todayStart, $todayEnd])
        ->sum('amount');

    $todayOrders = DeliveryOrder::where('rider_id', $rider->id)
        ->where('status', 'delivered')
        ->whereBetween('updated_at', [$todayStart, $todayEnd])
        ->count();

    $weekEarnings = RiderEarning::where('rider_id', $rider->id)
        ->whereBetween('created_at', [$weekStart, $weekEnd])
        ->sum('amount');

    $weekOrders = DeliveryOrder::where('rider_id', $rider->id)
        ->where('status', 'delivered')
        ->whereBetween('updated_at', [$weekStart, $weekEnd])
        ->count();

    $weekBonus = RiderEarning::where('rider_id', $rider->id)
        ->where('type', 'bonus')
        ->whereBetween('created_at', [$weekStart, $weekEnd])
        ->sum('amount');

    $monthEarnings = RiderEarning::where('rider_id', $rider->id)
        ->whereBetween('created_at', [$monthStart, $monthEnd])
        ->sum('amount');

    $monthOrders = DeliveryOrder::where('rider_id', $rider->id)
        ->where('status', 'delivered')
        ->whereBetween('updated_at', [$monthStart, $monthEnd])
        ->count();

    $monthBonus = RiderEarning::where('rider_id', $rider->id)
        ->where('type', 'bonus')
        ->whereBetween('created_at', [$monthStart, $monthEnd])
        ->sum('amount');

    return response()->json([
        'success' => true,

        'today' => [
            'earnings' => (float) $todayEarnings,
            'orders' => $todayOrders,
            'bonus' => (float) $todayBonus,
        ],

        'week' => [
            'earnings' => (float) $weekEarnings,
            'orders' => $weekOrders,
            'bonus' => (float) $weekBonus,
        ],

        'month' => [
            'earnings' => (float) $monthEarnings,
            'orders' => $monthOrders,
            'bonus' => (float) $monthBonus,
        ],

        'wallet_balance' => (float) $rider->wallet_balance,
    ]);
}
public function earnings(Request $request)
{
    $rider = $request->user();

    $earnings = RiderEarning::where('rider_id', $rider->id)
        ->latest()
        ->get([
            'id',
            'title',
            'type',
            'amount',
            'description',
            'created_at',
        ]);

    return response()->json([
        'success' => true,
        'earnings' => $earnings,
    ]);
}

public function earningsAnalytics(Request $request)
{
    $rider = $request->user();

    $filter = $request->get('filter', 'today');

    $earningQuery = RiderEarning::where('rider_id', $rider->id);

    $orderQuery = DeliveryOrder::where('rider_id', $rider->id)
        ->where('status', 'delivered');

    switch ($filter) {

        case 'week':

            $earningQuery->whereBetween('created_at', [
                now()->startOfWeek(),
                now()->endOfWeek(),
            ]);

            $orderQuery->whereBetween('updated_at', [
                now()->startOfWeek(),
                now()->endOfWeek(),
            ]);

            break;

        case 'month':

            $earningQuery->whereBetween('created_at', [
                now()->startOfMonth(),
                now()->endOfMonth(),
            ]);

            $orderQuery->whereBetween('updated_at', [
                now()->startOfMonth(),
                now()->endOfMonth(),
            ]);

            break;

        case 'total':
            // No date filter
            break;

        default:

            $earningQuery->whereDate('created_at', today());

            $orderQuery->whereDate('updated_at', today());

            break;
    }

    $totalEarnings = (clone $earningQuery)->sum('amount');

    $bonus = (clone $earningQuery)
        ->where('type', 'bonus')
        ->sum('amount');

    $orders = (clone $orderQuery)->count();

    $averagePerOrder = $orders > 0
        ? round($totalEarnings / $orders)
        : 0;

        $previousTotal = 0;

switch ($filter) {

    case 'today':

        $previousTotal = RiderEarning::where('rider_id', $rider->id)
            ->whereDate('created_at', today()->subDay())
            ->sum('amount');

        break;

    case 'week':

        $previousTotal = RiderEarning::where('rider_id', $rider->id)
            ->whereBetween('created_at', [
                now()->subWeek()->startOfWeek(),
                now()->subWeek()->endOfWeek(),
            ])
            ->sum('amount');

        break;

    case 'month':

        $previousTotal = RiderEarning::where('rider_id', $rider->id)
            ->whereBetween('created_at', [
                now()->subMonth()->startOfMonth(),
                now()->subMonth()->endOfMonth(),
            ])
            ->sum('amount');

        break;

    case 'total':

        $previousTotal = 0;

        break;
}

$percentage = 0;
$direction = 'same';
$comparisonText = 'No change';

if ($previousTotal > 0) {

    $percentage = round((($totalEarnings - $previousTotal) / $previousTotal) * 100);

    if ($percentage > 0) {

        $direction = 'increase';

    } elseif ($percentage < 0) {

        $direction = 'decrease';

    }

    $comparisonText = ($percentage >= 0 ? '+' : '') . $percentage . '% vs previous';
}

$peakDay = (clone $earningQuery)
    ->selectRaw('DATE(created_at) as earning_date, SUM(amount) as total_amount')
    ->groupBy('earning_date')
    ->orderByDesc('total_amount')
    ->first();

$peakDayData = $peakDay
    ? [
        'day' => Carbon::parse($peakDay->earning_date)->format('D'),
        'date' => $peakDay->earning_date,
        'amount' => (float) $peakDay->total_amount,
    ]
    : [
        'day' => null,
        'date' => null,
        'amount' => 0,
    ];

    $chart = [];

switch ($filter) {

    case 'today':

        for ($i = 0; $i < 24; $i++) {

            $hour = now()->startOfDay()->addHours($i);
$endHour = (clone $hour)->addHour();

            $amount = RiderEarning::where('rider_id', $rider->id)
             ->whereBetween('created_at', [
    $hour,
    $endHour,
])
                ->sum('amount');

            $chart[] = [
                'label' => $hour->format('H:i'),
                'amount' => (float)$amount,
            ];
        }

        break;

    case 'week':

        for ($i = 0; $i < 7; $i++) {

            $day = now()->startOfWeek()->copy()->addDays($i);

            $amount = RiderEarning::where('rider_id', $rider->id)
                ->whereDate('created_at', $day)
                ->sum('amount');

            $chart[] = [
                'label' => $day->format('D'),
                'amount' => (float)$amount,
            ];
        }

        break;

    case 'month':

        $days = now()->daysInMonth;

        for ($i = 1; $i <= $days; $i++) {

            $date = now()->startOfMonth()->copy()->day($i);

            $amount = RiderEarning::where('rider_id', $rider->id)
                ->whereDate('created_at', $date)
                ->sum('amount');

            $chart[] = [
                'label' => $i,
                'amount' => (float)$amount,
            ];
        }

        break;

    case 'total':

        $chart = RiderEarning::where('rider_id', $rider->id)
            ->selectRaw('DATE_FORMAT(created_at,"%b %Y") as label')
            ->selectRaw('SUM(amount) as amount')
            ->groupBy('label')
            ->orderByRaw('MIN(created_at)')
            ->get()
            ->map(function ($row) {
                return [
                    'label' => $row->label,
                    'amount' => (float)$row->amount,
                ];
            })
            ->values();
}

return response()->json([
    'success' => true,

    'summary' => [
        'filter' => $filter,
        'wallet_balance' => (float) $rider->wallet_balance,
        'earnings' => (float) $totalEarnings,
        'orders' => $orders,
        'bonus' => (float) $bonus,
        'average_per_order' => $averagePerOrder,
    ],

    'comparison' => [
        'percentage' => abs($percentage),
        'direction' => $direction,
        'text' => $comparisonText,
    ],

    'peak_day' => $peakDayData,

    'chart' => $chart,
]);;
}

public function wallet(Request $request)
{
    $rider = $request->user();

    $totalCredit = RiderWalletTransaction::where(
        'rider_id',
        $rider->id
    )
    ->where('type', 'credit')
    ->sum('amount');

    $totalDebit = RiderWalletTransaction::where(
        'rider_id',
        $rider->id
    )
    ->where('type', 'debit')
    ->sum('amount');

    $pendingSettlement = RiderDailySettlement::where(
        'rider_id',
        $rider->id
    )
    ->where('status', 'pending')
    ->sum('total_amount');

    $nextPayout = RiderDailySettlement::where(
        'rider_id',
        $rider->id
    )
    ->where('status', 'pending')
    ->whereDate('payout_date', '>=', today())
    ->orderBy('payout_date')
    ->first();

    $availableBalance = max(
        0,
        (float) $rider->wallet_balance - (float) $pendingSettlement
    );

    return response()->json([
        'success' => true,
        'data' => [
            'wallet_balance' => (float) $rider->wallet_balance,
            'total_credit' => (float) $totalCredit,
            'total_debit' => (float) $totalDebit,
            'pending_settlement' => (float) $pendingSettlement,
            'available_balance' => $availableBalance,
            'next_payout_date' => $nextPayout?->payout_date?->format('Y-m-d'),
        ]
    ]);
}


public function walletHistory(Request $request)
{
    $rider = $request->user();

    $transactions = RiderWalletTransaction::where('rider_id', $rider->id)
        ->latest()
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
        'transactions' => $transactions,
    ]);
}

}