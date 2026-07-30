<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\VehicleType;
use App\Models\OrderType;
use App\Models\WorkZone;
use App\Models\DeliveryKit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\RiderDeliveryAddress;

class RiderOnboardingController extends Controller
{
    public function cities()
    {
        $cities = City::where('is_available', true)
            ->select('id', 'name', 'latitude', 'longitude')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'cities' => $cities,
        ]);
    }

    public function selectCity(Request $request)
{
    $validator = Validator::make($request->all(), [
        'city_id' => ['required', 'integer', 'exists:cities,id'],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $city = City::where('id', $request->city_id)
        ->where('is_available', true)
        ->first();

    if (!$city) {
        return response()->json([
            'success' => false,
            'message' => 'Service is not available in this city',
        ], 400);
    }

    $rider = $request->user();

    $rider->update([
        'city_id' => $city->id,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Work city selected successfully',
        'city' => [
            'id' => $city->id,
            'name' => $city->name,
            'latitude' => $city->latitude,
            'longitude' => $city->longitude,
        ],
    ]);
}

   public function onboardingStatus(Request $request)
{
    $rider = $request->user();

    return response()->json([
        'success' => true,
        'onboarding_completed' => (bool) $rider->onboarding_completed,

        'steps' => [
            'work_settings' => [
                'step' => 1,
                'title' => 'Work settings',
                'completed' => (bool) $rider->work_settings_completed,
                'locked' => false,
            ],

            'profile' => [
                'step' => 2,
                'title' => 'Profile',
                'completed' => (bool) $rider->profile_completed,
                'locked' => !(bool) $rider->work_settings_completed,
            ],

            'delivery_kit' => [
                'step' => 3,
                'title' => 'Get VegGo delivery kit',
                'completed' => (bool) $rider->delivery_kit_completed,
                'locked' => !(bool) $rider->profile_completed,
            ],
        ],
    ]);
}

    public function vehicleTypes()
{
    $vehicleTypes = VehicleType::where('is_active', true)
        ->select('id', 'name', 'code', 'image')
        ->orderBy('name')
        ->get();

    return response()->json([
        'success' => true,
        'vehicle_types' => $vehicleTypes,
    ]);
}

    public function saveWorkSettings(Request $request)
{
    $validator = Validator::make($request->all(), [
        'vehicle_type_id' => [
            'required',
            'integer',
            'exists:vehicle_types,id',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $vehicleType = VehicleType::where('id', $request->vehicle_type_id)
        ->where('is_active', true)
        ->first();

    if (!$vehicleType) {
        return response()->json([
            'success' => false,
            'message' => 'Selected vehicle type is not available',
        ], 400);
    }

    $rider = $request->user();

    if (
        $rider->vehicle_type_id &&
        $rider->vehicle_type_id != $vehicleType->id &&
        $rider->vehicle_type_selected_at &&
        $rider->vehicle_type_selected_at->addDays(30)->isFuture()
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Vehicle type can only be changed after 30 days',
            'can_change_at' => $rider->vehicle_type_selected_at
                ->copy()
                ->addDays(30)
                ->toDateTimeString(),
        ], 400);
    }

    $rider->update([
        'vehicle_type_id' => $vehicleType->id,
        'vehicle_type_selected_at' => now(),
        'work_settings_completed' => true,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Work settings completed successfully',
        'work_settings_completed' => true,
        'vehicle_type' => [
            'id' => $vehicleType->id,
            'name' => $vehicleType->name,
            'code' => $vehicleType->code,
        ],
    ]);
}

    public function saveProfile(Request $request)
{
    $validator = Validator::make($request->all(), [
        'first_name' => [
            'required',
            'string',
            'max:100',
        ],
        'last_name' => [
            'required',
            'string',
            'max:100',
        ],
        'gender' => [
            'required',
            'in:male,female',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    if (!$rider->work_settings_completed) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete work settings first',
        ], 400);
    }

    $rider->update([
        'first_name' => $request->first_name,
        'last_name' => $request->last_name,
        'name' => trim(
            $request->first_name . ' ' . $request->last_name
        ),
        'gender' => $request->gender,
        'personal_details_completed' => true,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Personal details saved successfully',
        'personal_details_completed' => true,
        'rider' => [
            'id' => $rider->id,
            'first_name' => $rider->first_name,
            'last_name' => $rider->last_name,
            'name' => $rider->name,
            'gender' => $rider->gender,
        ],
    ]);
}

    public function workZones(Request $request)
{
    $rider = $request->user();

    if (!$rider->city_id) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your work city first',
        ], 400);
    }

    $zones = WorkZone::where('city_id', $rider->city_id)
        ->where('is_active', true)
        ->select(
            'id',
            'name',
            'minimum_guarantee',
            'weekly_earning_upto',
            'is_best_zone'
        )
        ->orderByDesc('is_best_zone')
        ->orderBy('name')
        ->get();

    return response()->json([
        'success' => true,
        'zones' => $zones,
    ]);
}

public function selectWorkZone(Request $request)
{
    $validator = Validator::make($request->all(), [
        'work_zone_id' => [
            'required',
            'integer',
            'exists:work_zones,id',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    $zone = WorkZone::where('id', $request->work_zone_id)
        ->where('city_id', $rider->city_id)
        ->where('is_active', true)
        ->first();

    if (!$zone) {
        return response()->json([
            'success' => false,
            'message' => 'Selected work zone is not available for your city',
        ], 400);
    }

    $rider->update([
        'work_zone_id' => $zone->id,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Work zone selected successfully',
        'work_zone' => [
            'id' => $zone->id,
            'name' => $zone->name,
            'minimum_guarantee' => $zone->minimum_guarantee,
            'weekly_earning_upto' => $zone->weekly_earning_upto,
        ],
    ]);
}

public function orderTypes(Request $request)
{
    $rider = $request->user();

    if (!$rider->work_zone_id) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your work zone first',
        ], 400);
    }

    $orderTypes = OrderType::where('is_active', true)
        ->select(
            'id',
            'name',
            'code',
            'description',
            'minimum_guarantee',
            'image',
            'is_limited_offer'
        )
        ->get();

    return response()->json([
        'success' => true,
        'order_types' => $orderTypes,
    ]);
}

public function selectOrderType(Request $request)
{
    $validator = Validator::make($request->all(), [
        'order_type_id' => [
            'required',
            'integer',
            'exists:order_types,id',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    if (!$rider->work_zone_id) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your work zone first',
        ], 400);
    }

    $orderType = OrderType::where('id', $request->order_type_id)
        ->where('is_active', true)
        ->first();

    if (!$orderType) {
        return response()->json([
            'success' => false,
            'message' => 'Selected order type is not available',
        ], 400);
    }

    $rider->update([
        'order_type_id' => $orderType->id,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Order type selected successfully',
        'order_type' => [
            'id' => $orderType->id,
            'name' => $orderType->name,
            'code' => $orderType->code,
        ],
    ]);
}

public function deliveryKits(Request $request)
{
    $rider = $request->user();

    if (!$rider->profile_completed) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete your profile first',
        ], 400);
    }

    $kits = DeliveryKit::where('is_active', true)
        ->select(
            'id',
            'name',
            'code',
            'half_tshirt_quantity',
            'full_tshirt_quantity',
            'bag_quantity',
            'image'
        )
        ->get();

    return response()->json([
        'success' => true,
        'delivery_kits' => $kits,
    ]);
}

public function selectDeliveryKit(Request $request)
{
    $validator = Validator::make($request->all(), [
        'delivery_kit_id' => [
            'required',
            'integer',
            'exists:delivery_kits,id',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    if (!$rider->profile_completed) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete your profile first',
        ], 400);
    }

    $kit = DeliveryKit::where('id', $request->delivery_kit_id)
        ->where('is_active', true)
        ->first();

    if (!$kit) {
        return response()->json([
            'success' => false,
            'message' => 'Selected delivery kit is not available',
        ], 400);
    }

    $rider->update([
        'delivery_kit_id' => $kit->id,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Delivery kit selected successfully',
        'delivery_kit' => [
            'id' => $kit->id,
            'name' => $kit->name,
            'code' => $kit->code,
            'half_tshirt_quantity' => $kit->half_tshirt_quantity,
            'full_tshirt_quantity' => $kit->full_tshirt_quantity,
            'bag_quantity' => $kit->bag_quantity,
        ],
        'next_step' => 'tshirt_size',
    ]);
}

public function tshirtSizes(Request $request)
{
    $rider = $request->user();

    if (!$rider->delivery_kit_id) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your delivery kit first',
        ], 400);
    }

    return response()->json([
        'success' => true,
        'tshirt_sizes' => [
            'S',
            'M',
            'L',
            'XL',
            '2XL',
        ],
    ]);
}

public function selectTshirtSize(Request $request)
{
    $validator = Validator::make($request->all(), [
        'tshirt_size' => [
            'required',
            'string',
            'in:S,M,L,XL,2XL',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    if (!$rider->delivery_kit_id) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your delivery kit first',
        ], 400);
    }

    $rider->update([
        'tshirt_size' => $request->tshirt_size,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'T-shirt size selected successfully',
        'tshirt_size' => $rider->tshirt_size,
        'next_step' => 'delivery_address',
    ]);
}

public function saveDeliveryAddress(Request $request)
{
    $validator = Validator::make($request->all(), [
        'house_flat' => [
            'required',
            'string',
            'max:255',
        ],
        'locality' => [
            'required',
            'string',
            'max:255',
        ],
        'landmark' => [
            'nullable',
            'string',
            'max:255',
        ],
        'pincode' => [
            'required',
            'digits:6',
        ],
        'city' => [
            'required',
            'string',
            'max:100',
        ],
        'state' => [
            'required',
            'string',
            'max:100',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $rider = $request->user();

    if (!$rider->delivery_kit_id) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your delivery kit first',
        ], 400);
    }

    if (!$rider->tshirt_size) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your T-shirt size first',
        ], 400);
    }

    $address = RiderDeliveryAddress::updateOrCreate(
        [
            'rider_id' => $rider->id,
        ],
        [
            'house_flat' => $request->house_flat,
            'locality' => $request->locality,
            'landmark' => $request->landmark,
            'pincode' => $request->pincode,
            'city' => $request->city,
            'state' => $request->state,
        ]
    );

    return response()->json([
        'success' => true,
        'message' => 'Delivery address saved successfully',
        'delivery_address' => [
            'id' => $address->id,
            'house_flat' => $address->house_flat,
            'locality' => $address->locality,
            'landmark' => $address->landmark,
            'pincode' => $address->pincode,
            'city' => $address->city,
            'state' => $address->state,
        ],
        'next_step' => 'confirm_delivery_kit',
    ]);
}


public function confirmDeliveryKit(Request $request)
{
    $rider = $request->user();

    if (!$rider->profile_completed) {
        return response()->json([
            'success' => false,
            'message' => 'Please complete your profile first',
        ], 400);
    }

    if (!$rider->delivery_kit_id) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your delivery kit',
        ], 400);
    }

    if (!$rider->tshirt_size) {
        return response()->json([
            'success' => false,
            'message' => 'Please select your T-shirt size',
        ], 400);
    }

    $address = RiderDeliveryAddress::where(
        'rider_id',
        $rider->id
    )->first();

    if (!$address) {
        return response()->json([
            'success' => false,
            'message' => 'Please add your delivery address',
        ], 400);
    }

    $kit = DeliveryKit::find($rider->delivery_kit_id);

    if (!$kit || !$kit->is_active) {
        return response()->json([
            'success' => false,
            'message' => 'Selected delivery kit is not available',
        ], 400);
    }

    $rider->update([
        'delivery_kit_completed' => true,
        'onboarding_completed' => true,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'VegGo onboarding completed successfully',
        'onboarding_completed' => true,
        'delivery_kit_completed' => true,
        'rider_status' => $rider->status,
        'delivery_kit' => [
            'id' => $kit->id,
            'name' => $kit->name,
            'tshirt_size' => $rider->tshirt_size,
        ],
        'next_step' => 'rider_dashboard',
    ]);
}


}