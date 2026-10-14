<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\CityArea;
use App\Models\DeliveryKit;
use App\Models\DeliveryOrder;
use App\Models\Rider;
use App\Models\RiderDeliveryAddress;
use App\Models\RiderKyc;
use App\Models\VehicleType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SuperAdminRiderController extends Controller
{
    public function index(Request $request)
    {
        $riders = Rider::query()
            ->select([
                'id', 'name', 'phone', 'city_id', 'city_area_id', 'status',
                'is_online', 'email', 'vehicle_type_id', 'vehicle_number',
                'work_zone_id', 'wallet_balance', 'latitude', 'longitude',
                'last_location_at', 'last_online_at', 'profile_completed',
                'onboarding_completed', 'created_at', 'updated_at',
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

        $riders->getCollection()->transform(function (Rider $rider) use ($currentOrders) {
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
                'status' => $rider->is_online ? 'Online' : 'Offline',
                'accountStatus' => $rider->status,
                'walletBalance' => $rider->wallet_balance,
                'latitude' => $rider->latitude,
                'longitude' => $rider->longitude,
                'lastOnlineAt' => $rider->last_online_at,
                'lastLocationUpdate' => $rider->last_location_at,
                'profileCompleted' => $rider->profile_completed,
                'onboardingCompleted' => $rider->onboarding_completed,
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

    public function riderOnboardingOptions()
    {
        return response()->json([
            'data' => [
                'cities' => City::query()
                    ->where('is_available', true)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'cityAreas' => CityArea::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'city_id', 'name']),
                'vehicleTypes' => VehicleType::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'code']),
                'deliveryKits' => DeliveryKit::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'code']),
            ],
        ]);
    }

    public function storeForOnboarding(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^[6-9][0-9]{9}$/', 'unique:riders,phone'],
            'email' => ['nullable', 'email', 'max:255'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'city_area_id' => ['required', 'integer', 'exists:city_areas,id'],
            'vehicle_type_id' => ['required', 'integer', 'exists:vehicle_types,id'],
            'vehicle_number' => ['nullable', 'string', 'max:30'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'aadhaar_number' => ['required', 'digits:12', 'regex:/^[2-9][0-9]{11}$/'],
            'has_pan' => ['required', 'boolean'],
            'pan_number' => ['required_if:has_pan,1', 'nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'driving_license_number' => ['required', 'string', 'max:30'],
            'delivery_kit_id' => ['required', 'integer', 'exists:delivery_kits,id'],
            'tshirt_size' => ['required', Rule::in(['S', 'M', 'L', 'XL', '2XL'])],
            'house_flat' => ['required', 'string', 'max:255'],
            'locality' => ['required', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'pincode' => ['required', 'digits:6'],
            'address_city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'aadhaar_document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'pan_document' => ['required_if:has_pan,1', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'driving_license_document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'puc_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'rc_book_document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'insurance_document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'selfie' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'phone.regex' => 'Enter a valid 10 digit mobile number.',
            'phone.unique' => 'A rider with this mobile number already exists.',
            'aadhaar_number.regex' => 'Enter a valid Aadhaar number.',
        ]);

        $city = City::query()
            ->whereKey($validated['city_id'])
            ->where('is_available', true)
            ->first();
        $cityArea = CityArea::query()
            ->whereKey($validated['city_area_id'])
            ->where('city_id', $validated['city_id'])
            ->where('is_active', true)
            ->first();
        $vehicleType = VehicleType::query()
            ->whereKey($validated['vehicle_type_id'])
            ->where('is_active', true)
            ->first();
        $deliveryKit = DeliveryKit::query()
            ->whereKey($validated['delivery_kit_id'])
            ->where('is_active', true)
            ->first();

        if (!$city || !$cityArea || !$vehicleType || !$deliveryKit) {
            throw ValidationException::withMessages([
                'onboarding' => 'One or more selected onboarding options are no longer available.',
            ]);
        }

        if ($vehicleType->code === 'petrol' && !$request->hasFile('puc_document')) {
            throw ValidationException::withMessages([
                'puc_document' => 'A PUC certificate is required for petrol vehicles.',
            ]);
        }

        $rider = DB::transaction(function () use ($request, $validated, $city, $cityArea, $vehicleType, $deliveryKit) {
            $rider = Rider::create([
                'name' => trim($validated['first_name'] . ' ' . $validated['last_name']),
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'city_id' => $city->id,
                'city_area_id' => $cityArea->id,
                'vehicle_type_id' => $vehicleType->id,
                'vehicle_type_selected_at' => now(),
                'vehicle_number' => isset($validated['vehicle_number'])
                    ? strtoupper($validated['vehicle_number'])
                    : null,
                'first_name' => trim($validated['first_name']),
                'last_name' => trim($validated['last_name']),
                'gender' => $validated['gender'],
                'work_settings_completed' => true,
                'personal_details_completed' => true,
                'profile_completed' => true,
                'delivery_kit_id' => $deliveryKit->id,
                'delivery_kit_completed' => true,
                'tshirt_size' => $validated['tshirt_size'],
                'delivery_bag_amount' => 999.00,
                'delivery_bag_payment_status' => 'pending',
                'onboarding_completed' => true,
                'status' => 'pending',
            ]);

            $documentPaths = [];
            foreach ([
                'aadhaar_document' => 'riders/kyc/aadhaar',
                'pan_document' => 'riders/kyc/pan',
                'driving_license_document' => 'riders/kyc/driving-license',
                'puc_document' => 'riders/kyc/puc',
                'rc_book_document' => 'riders/kyc/rc-book',
                'insurance_document' => 'riders/kyc/insurance',
                'selfie' => 'riders/selfies',
            ] as $field => $directory) {
                if ($request->hasFile($field)) {
                    $path = $request->file($field)->store($directory, 'public');
                    if (!$path) {
                        throw new \RuntimeException("Unable to store {$field}.");
                    }
                    $documentPaths[$field] = $path;
                }
            }

            RiderKyc::create([
                'rider_id' => $rider->id,
                'aadhaar_number' => $validated['aadhaar_number'],
                'aadhaar_last_four' => substr($validated['aadhaar_number'], -4),
                'aadhaar_document' => $documentPaths['aadhaar_document'],
                'aadhaar_verified' => false,
                'has_pan' => (bool) $validated['has_pan'],
                'pan_number' => $validated['has_pan'] ? strtoupper($validated['pan_number']) : null,
                'pan_document' => $documentPaths['pan_document'] ?? null,
                'pan_verified' => false,
                'driving_license_number' => strtoupper($validated['driving_license_number']),
                'driving_license_document' => $documentPaths['driving_license_document'],
                'driving_license_verified' => false,
                'puc_document' => $documentPaths['puc_document'] ?? null,
                'puc_verified' => false,
                'rc_book_document' => $documentPaths['rc_book_document'],
                'rc_book_verified' => false,
                'insurance_document' => $documentPaths['insurance_document'],
                'insurance_verified' => false,
                'selfie' => $documentPaths['selfie'],
                'status' => 'pending',
            ]);

            RiderDeliveryAddress::create([
                'rider_id' => $rider->id,
                'house_flat' => $validated['house_flat'],
                'locality' => $validated['locality'],
                'landmark' => $validated['landmark'] ?? null,
                'pincode' => $validated['pincode'],
                'city' => $validated['address_city'],
                'state' => $validated['state'],
            ]);

            return $rider;
        });

        return response()->json([
            'message' => 'Rider onboarding completed. The KYC documents are pending admin review.',
            'data' => [
                'id' => $rider->id,
                'onboarding_completed' => true,
                'status' => $rider->status,
            ],
        ], 201);
    }

    public function updateOnline(Request $request, $id)
    {
        $validated = $request->validate([
            'is_online' => ['required', 'boolean'],
        ]);

        $rider = Rider::find($id);
        if (!$rider) {
            return response()->json(['message' => 'Rider not found.'], 404);
        }

        if ($validated['is_online'] && $rider->status !== 'approved') {
            return response()->json(['message' => 'Only active riders can be set online.'], 422);
        }

        if ($validated['is_online'] && !$rider->onboarding_completed) {
            return response()->json(['message' => 'Rider onboarding must be completed before going online.'], 422);
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

    public function orders($id)
    {
        if (!Rider::whereKey($id)->exists()) {
            return response()->json(['message' => 'Rider not found.'], 404);
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
}
