<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\DeliveryOrder;
use App\Models\Rider;
use App\Models\VehicleType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminRiderController extends Controller
{
    private const ACCOUNT_STATUSES = ['pending', 'approved', 'rejected', 'blocked'];

    /**
     * Get rider list
     */
    public function index(Request $request)
    {
        $riders = Rider::query()
            ->select([
                'id',
                'name',
                'phone',
                'city_id',
                'city_area_id',
                'status',
                'is_online',
                'email',
                'vehicle_type_id',
                'vehicle_number',
                'work_zone_id',
                'wallet_balance',
                'latitude',
                'longitude',
                'last_location_at',
                'last_online_at',
                'profile_completed',
                'onboarding_completed',
                'created_at',
                'updated_at',
            ])
            ->with([
                'city:id,name',
                'cityArea:id,name',
                'vehicleType:id,name',
                'workZone:id,name',
                'kyc:id,rider_id,status',
            ])
            ->withCount([
                'deliveryOrders as completed_deliveries' => fn ($query) =>
                    $query->where('status', 'delivered'),
            ])
            ->withSum('earnings as total_earnings', 'amount')
            ->withSum([
                'earnings as week_earnings' => fn ($query) =>
                    $query->where('created_at', '>=', now()->startOfWeek()),
            ], 'amount')
            ->latest('id')
            ->paginate(10);

        $currentOrders = DeliveryOrder::query()
            ->whereIn('rider_id', $riders->getCollection()->pluck('id'))
            ->whereIn('status', ['accepted', 'arrived', 'picked_up'])
            ->latest('updated_at')
            ->get(['id', 'rider_id', 'order_number', 'status'])
            ->unique('rider_id')
            ->keyBy('rider_id');

        $riders->getCollection()->transform(function ($rider) use ($currentOrders) {
            $currentOrder = $currentOrders->get($rider->id);

            return [
                'id' => $rider->id,

                'name' => $rider->name,

                'mobile' => $rider->phone,

                'email' => $rider->email,

                'city' => $rider->city?->name,

                'location' => $rider->cityArea?->name,

                'address' => null,

                'vehicleType' => $rider->vehicleType?->name,

                'vehicleTypeId' => $rider->vehicle_type_id,

                'vehicleNumber' => $rider->vehicle_number,

                'status' => $rider->is_online
                    ? 'Online'
                    : 'Offline',

                'accountStatus' => $rider->status,

                'walletBalance' => $rider->wallet_balance,

                'latitude' => $rider->latitude,

                'longitude' => $rider->longitude,

                'lastOnlineAt' => $rider->last_online_at,
                'lastLocationUpdate' => $rider->last_location_at,

                'profileCompleted' => $rider->profile_completed,

                'onboardingCompleted' =>
                    $rider->onboarding_completed,

                'totalDeliveries' => (int) $rider->completed_deliveries,

                'completedDeliveries' => (int) $rider->completed_deliveries,

                'cancelledDeliveries' => null,

                'rating' => null,

                'deliveryEarnings' => null,

                'incentives' => null,

                'bonuses' => null,

                'adjustments' => null,

                'totalEarnings' => (float) ($rider->total_earnings ?? 0),

                'weekEarnings' => (float) ($rider->week_earnings ?? 0),

                'paidAmount' => null,

                'pendingAmount' => null,

                'currentOrder' => $currentOrder?->order_number,

                'currentOrderStatus' => $currentOrder?->status,

                'verification' => $rider->kyc?->status,

                'joinedDate' => $rider->created_at,
            ];
        });

        return response()->json([
            'message' => 'Riders fetched successfully.',
            'data' => $riders,
        ]);
    }


    /**
     * Active vehicle types (for filters / add rider form)
     */
    public function vehicleTypes()
    {
        return response()->json([
            'message' => 'Vehicle types fetched successfully.',
            'data' => VehicleType::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }


    /**
     * Create rider from admin panel
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^[6-9][0-9]{9}$/', 'unique:riders,phone'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'vehicle_type_id' => ['nullable', 'exists:vehicle_types,id'],
            'vehicle_number' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(self::ACCOUNT_STATUSES)],
        ], [
            'phone.regex' => 'Enter a valid 10 digit mobile number.',
            'phone.unique' => 'A rider with this mobile number already exists.',
        ]);

        $cityId = null;

        if (!empty($validated['city'])) {
            $cityId = City::query()
                ->whereRaw('LOWER(name) = ?', [strtolower(trim($validated['city']))])
                ->value('id');

            if (!$cityId) {
                throw ValidationException::withMessages([
                    'city' => 'City not found. Please enter a city served by VegGo.',
                ]);
            }
        }

        $rider = Rider::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'city_id' => $cityId,
            'vehicle_type_id' => $validated['vehicle_type_id'] ?? null,
            'vehicle_type_selected_at' => !empty($validated['vehicle_type_id']) ? now() : null,
            'vehicle_number' => isset($validated['vehicle_number'])
                ? strtoupper($validated['vehicle_number'])
                : null,
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Rider created successfully.',
            'data' => ['id' => $rider->id],
        ], 201);
    }

    /**
     * Update rider account status
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::ACCOUNT_STATUSES)],
        ]);

        $rider = Rider::find($id);

        if (!$rider) {
            return response()->json([
                'message' => 'Rider not found.',
            ], 404);
        }

        $rider->status = $validated['status'];

        // A blocked rider cannot stay online
        if ($validated['status'] === 'blocked') {
            $rider->is_online = false;
        }

        $rider->save();

        return response()->json([
            'message' => 'Rider status updated successfully.',
            'data' => [
                'id' => $rider->id,
                'accountStatus' => $rider->status,
                'status' => $rider->is_online ? 'Online' : 'Offline',
            ],
        ]);
    }


    /**
     * Force rider online / offline
     */
    public function updateOnline(Request $request, $id)
    {
        $validated = $request->validate([
            'is_online' => ['required', 'boolean'],
        ]);

        $rider = Rider::find($id);

        if (!$rider) {
            return response()->json([
                'message' => 'Rider not found.',
            ], 404);
        }

        if ($validated['is_online'] && $rider->status !== 'approved') {
            return response()->json([
                'message' => 'Only active riders can be set online.',
            ], 422);
        }

        if ($validated['is_online'] && !$rider->onboarding_completed) {
            return response()->json([
                'message' => 'Rider onboarding must be completed before going online.',
            ], 422);
        }

        $rider->is_online = $validated['is_online'];

        if ($rider->is_online) {
            $rider->last_online_at = now();
        }

        $rider->save();

        return response()->json([
            'message' => 'Rider online status updated successfully.',
            'data' => [
                'id' => $rider->id,
                'status' => $rider->is_online ? 'Online' : 'Offline',
            ],
        ]);
    }

    /**
     * Get a rider's delivery history
     */
    public function orders($id)
    {
        if (!Rider::whereKey($id)->exists()) {
            return response()->json([
                'message' => 'Rider not found.',
            ], 404);
        }

        $orders = DeliveryOrder::query()
            ->where('rider_id', $id)
            ->latest('created_at')
            ->limit(100)
            ->get([
                'id',
                'order_number',
                'restaurant_name',
                'customer_name',
                'status',
                'accepted_at',
                'delivered_at',
                'rider_earning',
                'created_at',
            ]);

        return response()->json([
            'message' => 'Rider delivery history fetched successfully.',
            'data' => $orders,
        ]);
    }


    /**
     * Get rider details
     */
    public function show($id)
    {
        $rider = Rider::query()
            ->select([
                'id',
                'name',
                'phone',
                'email',
                'language',
                'city_id',
                'city_area_id',
                'status',
                'onboarding_completed',
                'work_settings_completed',
                'personal_details_completed',
                'profile_completed',
                'delivery_kit_completed',
                'vehicle_type_id',
                'vehicle_number',
                'vehicle_type_selected_at',
                'first_name',
                'last_name',
                'gender',
                'work_zone_id',
                'order_type_id',
                'delivery_kit_id',
                'delivery_bag_amount',
                'delivery_bag_payment_status',
                'delivery_bag_paid_at',
                'tshirt_size',
                'is_online',
                'wallet_balance',
                'last_online_at',
                'latitude',
                'longitude',
                'heading',
                'speed',
                'last_location_at',
                'referral_code',
                'created_at',
                'updated_at',
            ])
            ->with([
                'city:id,name',
                'cityArea:id,name',
                'vehicleType:id,name',
                'workZone:id,name',
            ])
            ->find($id);

        if (!$rider) {
            return response()->json([
                'message' => 'Rider not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Rider details fetched successfully.',

            'data' => [
                'id' => $rider->id,

                'name' => $rider->name,

                'mobile' => $rider->phone,

                'email' => $rider->email,

                'city' => $rider->city?->name,

                'location' => $rider->cityArea?->name,

                'address' => null,

                'vehicleType' =>
                    $rider->vehicleType?->name,

                'vehicleNumber' => $rider->vehicle_number,

                'status' => $rider->is_online
                    ? 'Online'
                    : 'Offline',

                'accountStatus' =>
                    $rider->status,

                'walletBalance' =>
                    $rider->wallet_balance,

                'latitude' =>
                    $rider->latitude,

                'longitude' =>
                    $rider->longitude,

                'heading' =>
                    $rider->heading,

                'speed' =>
                    $rider->speed,

                'lastLocationUpdate' =>
                    $rider->last_location_at,

                'lastOnlineAt' =>
                    $rider->last_online_at,

                'profileCompleted' =>
                    $rider->profile_completed,

                'onboardingCompleted' =>
                    $rider->onboarding_completed,

                'workSettingsCompleted' =>
                    $rider->work_settings_completed,

                'personalDetailsCompleted' =>
                    $rider->personal_details_completed,

                'deliveryKitCompleted' =>
                    $rider->delivery_kit_completed,

                'firstName' =>
                    $rider->first_name,

                'lastName' =>
                    $rider->last_name,

                'gender' =>
                    $rider->gender,

                'language' =>
                    $rider->language,

                'workZone' =>
                    $rider->workZone?->name,

                'deliveryBagAmount' =>
                    $rider->delivery_bag_amount,

                'deliveryBagPaymentStatus' =>
                    $rider->delivery_bag_payment_status,

                'deliveryBagPaidAt' =>
                    $rider->delivery_bag_paid_at,

                'tshirtSize' =>
                    $rider->tshirt_size,

                'referralCode' =>
                    $rider->referral_code,

                'vehicleTypeId' =>
                    $rider->vehicle_type_id,

                'vehicleTypeSelectedAt' =>
                    $rider->vehicle_type_selected_at,

                'totalDeliveries' => null,

                'completedDeliveries' => null,

                'cancelledDeliveries' => null,

                'rating' => null,

                'deliveryEarnings' => null,

                'incentives' => null,

                'bonuses' => null,

                'adjustments' => null,

                'totalEarnings' => null,

                'paidAmount' => null,

                'pendingAmount' => null,

                'currentOrder' => null,

                'joinedDate' =>
                    $rider->created_at,
            ],
        ]);
    }
}