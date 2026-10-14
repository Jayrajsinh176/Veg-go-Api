<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminRestaurantController extends Controller
{
    /**
     * Get restaurant list
     */
    public function index(Request $request)
    {
        $restaurants = Restaurant::query()
            ->select([
                'id',
                'owner_name',
                'restaurant_name',
                'mobile',
                'email',
                'status',
                'is_online',
                'is_profile_completed',
                'commission_percentage',
                'created_at',
                'updated_at',
            ])
            ->with([
                'address' => function ($query) {
                    $query->select([
                        'id',
                        'restaurant_id',
                        'full_address',
                        'shop_plot_number',
                        'floor',
                        'building_name',
                        'pincode',
                        'complete_address',
                        'latitude',
                        'longitude',
                        'landmark',
                    ]);
                },
            ])
            ->withCount('orders')
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->withSum([
                'orders as revenue' => function ($query) {
                    $query->where('order_status', '!=', 'cancelled');
                },
            ], 'total_amount')
            ->withSum('orders as commission_earned', 'commission_amount')
            ->withSum('orders as restaurant_earnings', 'restaurant_earning')
            ->latest('id')
            ->paginate(10);

        $defaultCommission = (float) config(
            'restaurant.commission_percentage',
            30.00
        );

        $restaurants->getCollection()->transform(function ($restaurant) use ($defaultCommission) {

            $address = $restaurant->address;

            return [
                'id' => $restaurant->id,

                'name' => $restaurant->restaurant_name,

                'owner' => $restaurant->owner_name,

                'mobile' => $restaurant->mobile,

                'email' => $restaurant->email,

                'city' => null,

                'location' => $address?->landmark,

                'commission' => $restaurant->commissionRate(),

                // true when the restaurant uses the default rate (no custom rate set)
                'is_default_commission' =>
                    $restaurant->commission_percentage === null,

                'default_commission' => $defaultCommission,

                'orders_count' => (int) $restaurant->orders_count,

                'rating' => round((float) $restaurant->reviews_avg_rating, 1),

                'reviews_count' => (int) $restaurant->reviews_count,

                'revenue' => round((float) $restaurant->revenue, 2),

                'commission_earned' =>
                    round((float) $restaurant->commission_earned, 2),

                'restaurant_earnings' =>
                    round((float) $restaurant->restaurant_earnings, 2),

                'address' => $address?->full_address
                    ?? $address?->complete_address,

                'radius' => null,

                'deliveryTime' => null,

                'status' => $restaurant->status,

                'is_online' => $restaurant->is_online,

                'is_profile_completed' =>
                    $restaurant->is_profile_completed,
            ];
        });

        return response()->json([
            'message' => 'Restaurants fetched successfully.',
            'data' => $restaurants,
        ]);
    }

    /**
 * Get restaurant details
 */
public function show($id)
{
    $restaurant = Restaurant::query()
        ->select([
            'id',
            'owner_name',
            'restaurant_name',
            'mobile',
            'email',
            'whatsapp_number',
            'is_whatsapp_same',
            'same_time_all_days',
            'status',
            'is_online',
            'wallet_balance',
            'commission_percentage',
            'onboarding_step',
            'is_profile_completed',
            'created_at',
            'updated_at',
        ])
        ->with([
            'address' => function ($query) {
                $query->select([
                    'id',
                    'restaurant_id',
                    'full_address',
                    'shop_plot_number',
                    'floor',
                    'building_name',
                    'pincode',
                    'complete_address',
                    'latitude',
                    'longitude',
                    'landmark',
                ]);
            },

            'workingHours',

            'workingDays',

            'document',

            'settings',
        ])
        ->find($id);

    if (!$restaurant) {
        return response()->json([
            'message' => 'Restaurant not found.',
        ], 404);
    }

    $address = $restaurant->address;

    return response()->json([
        'message' => 'Restaurant details fetched successfully.',

        'data' => [
            'id' => $restaurant->id,

            'name' => $restaurant->restaurant_name,

            'owner' => $restaurant->owner_name,

            'mobile' => $restaurant->mobile,

            'email' => $restaurant->email,

            'whatsapp_number' =>
                $restaurant->whatsapp_number,

            'is_whatsapp_same' =>
                $restaurant->is_whatsapp_same,

            'same_time_all_days' =>
                $restaurant->same_time_all_days,

            'status' =>
                $restaurant->status,

            'is_online' =>
                $restaurant->is_online,

            'wallet_balance' =>
                $restaurant->wallet_balance,

            'commission' =>
                $restaurant->commissionRate(),

            'is_default_commission' =>
                $restaurant->commission_percentage === null,

            'onboarding_step' =>
                $restaurant->onboarding_step,

            'is_profile_completed' =>
                $restaurant->is_profile_completed,

            'address' => $address
                ? [
                    'id' => $address->id,

                    'full_address' =>
                        $address->full_address,

                    'shop_plot_number' =>
                        $address->shop_plot_number,

                    'floor' =>
                        $address->floor,

                    'building_name' =>
                        $address->building_name,

                    'pincode' =>
                        $address->pincode,

                    'complete_address' =>
                        $address->complete_address,

                    'latitude' =>
                        $address->latitude,

                    'longitude' =>
                        $address->longitude,

                    'landmark' =>
                        $address->landmark,
                ]
                : null,

            'working_hours' =>
                $restaurant->workingHours,

            'working_days' =>
                $restaurant->workingDays,

            'document' =>
                $restaurant->document,

            'delivery_fee' => (float) config(
                'restaurant.delivery_charge',
                9
            ),

            'delivery_radius_km' => null,

            'default_prep_time' =>
                $restaurant->settings?->default_prep_time,

            'created_at' =>
                $restaurant->created_at,

            'updated_at' =>
                $restaurant->updated_at,
        ],
    ]);
}


/**
 * Update restaurant basic details
 */
public function update(Request $request, $id)
{
    $restaurant = Restaurant::with('address')->find($id);

    if (!$restaurant) {
        return response()->json([
            'message' => 'Restaurant not found.',
        ], 404);
    }

    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'owner' => ['required', 'string', 'max:255'],
        'mobile' => [
            'required',
            'digits:10',
            Rule::unique('restaurants', 'mobile')->ignore($restaurant->id),
        ],
        'email' => ['nullable', 'email', 'max:255'],
        'location' => ['nullable', 'string', 'max:255'],
        'address' => ['nullable', 'string', 'max:255'],
    ]);

    $address = $restaurant->address;

    $addressChanged = $address
        ? false
        : filled($validated['location'] ?? null) || filled($validated['address'] ?? null);

    // An address row needs plot/building/pincode from onboarding,
    // so admin can only edit an existing one
    if ($addressChanged) {
        return response()->json([
            'message' => 'This restaurant has no address on file yet. It must be added from the restaurant app.',
        ], 422);
    }

    DB::transaction(function () use ($restaurant, $address, $validated) {
        $restaurant->update([
            'restaurant_name' => $validated['name'],
            'owner_name' => $validated['owner'],
            'mobile' => $validated['mobile'],
            'email' => $validated['email'] ?? null,
        ]);

        if ($address) {
            $address->update([
                'landmark' => $validated['location'] ?? null,
                'full_address' => filled($validated['address'] ?? null)
                    ? $validated['address']
                    : $address->full_address,
            ]);
        }
    });

    $address = $restaurant->fresh('address')->address;

    return response()->json([
        'message' => 'Restaurant updated successfully.',

        'data' => [
            'id' => $restaurant->id,
            'name' => $restaurant->restaurant_name,
            'owner' => $restaurant->owner_name,
            'mobile' => $restaurant->mobile,
            'email' => $restaurant->email,
            'location' => $address?->landmark,
            'address' => $address?->full_address
                ?? $address?->complete_address,
        ],
    ]);
}

/**
 * Update restaurant commission rate
 *
 * Applies to orders credited from now on. Orders already credited keep
 * the rate stored on them. Send null to go back to the default rate.
 */
public function updateCommission(Request $request, $id)
{
    $restaurant = Restaurant::find($id);

    if (!$restaurant) {
        return response()->json([
            'message' => 'Restaurant not found.',
        ], 404);
    }

    $validated = $request->validate([
        'commission' => ['nullable', 'numeric', 'min:0', 'max:100'],
    ]);

    $restaurant->update([
        'commission_percentage' => $validated['commission'] ?? null,
    ]);

    return response()->json([
        'message' => 'Commission updated successfully.',

        'data' => [
            'id' => $restaurant->id,
            'commission' => $restaurant->commissionRate(),
            'is_default_commission' =>
                $restaurant->commission_percentage === null,
            'default_commission' => (float) config(
                'restaurant.commission_percentage',
                30.00
            ),
        ],
    ]);
}

/**
 * Suspend restaurant
 *
 * Takes the restaurant offline and blocks login and every restaurant
 * API call until admin lifts the suspension.
 */
public function suspend($id)
{
    $restaurant = Restaurant::find($id);

    if (!$restaurant) {
        return response()->json([
            'message' => 'Restaurant not found.',
        ], 404);
    }

    if ($restaurant->status === 'suspended') {
        return response()->json([
            'message' => 'Restaurant is already suspended.',
        ], 422);
    }

    // Tokens are kept so the app gets the "suspended" response
    // (EnsureRestaurantNotSuspended) instead of a plain 401
    $restaurant->update([
        'status_before_suspension' => $restaurant->status,
        'status' => 'suspended',
        'suspended_at' => now(),
        'is_online' => false,
    ]);

    return response()->json([
        'message' => 'Restaurant suspended successfully.',

        'data' => [
            'id' => $restaurant->id,
            'status' => $restaurant->status,
            'is_online' => $restaurant->is_online,
        ],
    ]);
}

/**
 * Lift restaurant suspension and restore its previous status
 */
public function unsuspend($id)
{
    $restaurant = Restaurant::find($id);

    if (!$restaurant) {
        return response()->json([
            'message' => 'Restaurant not found.',
        ], 404);
    }

    if ($restaurant->status !== 'suspended') {
        return response()->json([
            'message' => 'Restaurant is not suspended.',
        ], 422);
    }

    $restaurant->update([
        'status' => $restaurant->status_before_suspension ?? 'approved',
        'status_before_suspension' => null,
        'suspended_at' => null,
    ]);

    return response()->json([
        'message' => 'Restaurant suspension lifted successfully.',

        'data' => [
            'id' => $restaurant->id,
            'status' => $restaurant->status,
            'is_online' => $restaurant->is_online,
        ],
    ]);
}

/**
 * Get restaurant daily earnings & settlements
 */
public function earnings($id)
{
    $restaurant = Restaurant::find($id);

    if (!$restaurant) {
        return response()->json([
            'message' => 'Restaurant not found.',
        ], 404);
    }

    // Restaurant's own rate, else the default 30%
    $commissionRate = $restaurant->commissionRate();

    $orders = RestaurantOrder::query()
        ->where('restaurant_id', $restaurant->id)
        ->where('order_status', '!=', 'cancelled')
        ->get([
            'id',
            'subtotal',
            'commission_amount',
            'restaurant_earning',
            'earning_status',
            'placed_at',
            'created_at',
        ]);

    $days = $orders
        ->groupBy(function ($order) {
            $placedAt = $order->placed_at
                ? Carbon::parse($order->placed_at)
                : $order->created_at;

            return $placedAt->toDateString();
        })
        ->map(function ($dayOrders, $date) use ($commissionRate) {
            $gross = 0;
            $commission = 0;

            foreach ($dayOrders as $order) {
                $subtotal = (float) $order->subtotal;

                $gross += $subtotal;

                // Commission is stored once the earning is credited;
                // until then show what it will be at the current rate
                $commission += $order->earning_status === 'pending'
                    ? round(($subtotal * $commissionRate) / 100, 2)
                    : (float) $order->commission_amount;
            }

            $statuses = $dayOrders->pluck('earning_status')->unique();

            $status = match (true) {
                $statuses->contains('pending') => 'processing',
                $statuses->every(fn ($status) => $status === 'reversed') => 'reversed',
                default => 'settled',
            };

            return [
                'date' => $date,
                'orders' => $dayOrders->count(),
                'gross' => round($gross, 2),
                'commission' => round($commission, 2),
                'net_payout' => round($gross - $commission, 2),
                'status' => $status,
            ];
        })
        ->sortKeysDesc()
        ->values();

    return response()->json([
        'message' => 'Restaurant earnings fetched successfully.',

        'data' => [
            'commission_rate' => $commissionRate,
            'days' => $days,
        ],
    ]);
}

/**
 * Get restaurant performance metrics
 */
public function performance($id)
{
    $restaurant = Restaurant::with('settings')->find($id);

    if (!$restaurant) {
        return response()->json([
            'message' => 'Restaurant not found.',
        ], 404);
    }

    $orders = RestaurantOrder::query()
        ->where('restaurant_id', $restaurant->id)
        ->get([
            'id',
            'order_status',
            'placed_at',
            'accepted_at',
            'ready_for_pickup_at',
            'cancelled_at',
            'created_at',
        ])
        ->map(function ($order) {
            // Timeline columns are not cast on the model
            foreach (['placed_at', 'accepted_at', 'ready_for_pickup_at', 'cancelled_at'] as $column) {
                $order->{$column} = $order->{$column}
                    ? Carbon::parse($order->{$column})
                    : null;
            }

            return $order;
        });

    $prepTime = (int) ($restaurant->settings?->default_prep_time ?? 30);

    $percent = fn ($part, $total) => $total > 0
        ? round(($part / $total) * 100, 1)
        : null;

    // Acceptance: accepted orders vs orders the restaurant decided on
    // (a rejected order is cancelled without ever being accepted)
    $acceptance = function ($orders) use ($percent) {
        $accepted = $orders->whereNotNull('accepted_at')->count();

        $rejected = $orders
            ->where('order_status', 'cancelled')
            ->whereNull('accepted_at')
            ->count();

        return $percent($accepted, $accepted + $rejected);
    };

    $placedAt = fn ($order) => $order->placed_at ?? $order->created_at;

    $thisMonth = $orders->filter(
        fn ($order) => $placedAt($order)?->gte(now()->startOfMonth())
    );

    $lastMonth = $orders->filter(
        fn ($order) => $placedAt($order)?->between(
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth()
        )
    );

    // On time: food ready within the restaurant prep time after accepting
    $prepared = $orders->filter(
        fn ($order) => $order->accepted_at && $order->ready_for_pickup_at
    );

    $onTime = $prepared->filter(
        fn ($order) => $order->accepted_at->diffInMinutes($order->ready_for_pickup_at) <= $prepTime
    )->count();

    // Orders per day for the current week (Mon - Sun)
    $weekStart = now()->startOfWeek(Carbon::MONDAY);

    $weekly = collect(range(0, 6))->map(function ($offset) use ($orders, $placedAt, $weekStart) {
        $day = $weekStart->copy()->addDays($offset);

        return [
            'day' => $day->format('D'),
            'date' => $day->toDateString(),
            'orders' => $orders->filter(
                fn ($order) => $placedAt($order)?->isSameDay($day)
            )->count(),
        ];
    });

    return response()->json([
        'message' => 'Restaurant performance fetched successfully.',

        'data' => [
            'total_orders' => $orders->count(),

            'acceptance_rate' => $acceptance($orders),

            'acceptance_rate_this_month' => $acceptance($thisMonth),

            'acceptance_rate_last_month' => $acceptance($lastMonth),

            'on_time_rate' => $percent($onTime, $prepared->count()),

            'prep_time_minutes' => $prepTime,

            'cancellation_rate' => $percent(
                $orders->where('order_status', 'cancelled')->count(),
                $orders->count()
            ),

            'weekly_orders' => $weekly,
        ],
    ]);
}

}