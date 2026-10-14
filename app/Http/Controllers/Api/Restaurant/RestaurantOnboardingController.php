<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\RestaurantAddress;
use App\Models\RestaurantWorkingDay;
use App\Models\RestaurantWorkingHour;
use App\Models\RestaurantOutletType;
use App\Models\RestaurantDocument;
use App\Models\RestaurantPartnerContract;
use App\Models\RestaurantCategory;
use App\Models\RestaurantMenuItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class RestaurantOnboardingController extends Controller
{
    /**
     * Get Restaurant Onboarding Status
     */
    public function onboardingStatus(Request $request)
    {
        $restaurant = $request->user();

        return response()->json([
            'success' => true,
            'restaurant' => [
                'id' => $restaurant->id,
                'mobile' => $restaurant->mobile,
                'owner_name' => $restaurant->owner_name,
                'restaurant_name' => $restaurant->restaurant_name,
                'onboarding_step' => $restaurant->onboarding_step,
                'is_profile_completed' => $restaurant->is_profile_completed,
                'status' => $restaurant->status,
            ],
        ]);
    }

    public function saveProfile(Request $request)
{
    $request->validate([
        'owner_name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
        'whatsapp_number' => 'required|string|max:15',
        'is_whatsapp_same' => 'required|boolean',
    ]);

    $restaurant = $request->user();

    $restaurant->update([
        'owner_name' => $request->owner_name,
        'email' => $request->email,
        'whatsapp_number' => $request->whatsapp_number,
        'is_whatsapp_same' => $request->is_whatsapp_same,
        'onboarding_step' => 2,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Restaurant profile saved successfully.',
        'restaurant' => $restaurant->fresh(),
    ]);
}

/**
 * Save Restaurant Address
 */
public function saveAddress(Request $request)
{
    $request->validate([
        'full_address' => 'required|string|max:255',
        'shop_plot_number' => 'required|string|max:100',
        'floor' => 'nullable|string|max:100',
        'building_name' => 'required|string|max:255',
        'pincode' => 'required|string|max:10',
        'complete_address' => 'nullable|string',
        'latitude' => 'nullable|numeric',
        'longitude' => 'nullable|numeric',
        'landmark' => 'nullable|string|max:255',
        'restaurant_front_image' => 'nullable|string',
    ]);

    $restaurant = $request->user();

    $address = RestaurantAddress::updateOrCreate(
        [
            'restaurant_id' => $restaurant->id,
        ],
        [
            'full_address' => $request->full_address,
            'shop_plot_number' => $request->shop_plot_number,
            'floor' => $request->floor,
            'building_name' => $request->building_name,
            'pincode' => $request->pincode,
            'complete_address' => $request->complete_address,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'landmark' => $request->landmark,
            'restaurant_front_image' => $request->restaurant_front_image,
        ]
    );

    return response()->json([
        'success' => true,
        'message' => 'Restaurant address saved successfully.',
        'address' => $address,
    ]);
}

public function saveWorkingDays(Request $request)
{
    $validator = Validator::make($request->all(), [
        'days' => ['required', 'array', 'min:1'],
        'days.*' => [
            'required',
            'in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $restaurant = $request->user();

    RestaurantWorkingDay::where('restaurant_id', $restaurant->id)->delete();

    foreach ($request->days as $day) {
        RestaurantWorkingDay::create([
            'restaurant_id' => $restaurant->id,
            'day' => $day,
        ]);
    }

    $restaurant->update([
        'onboarding_step' => 4,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Working days saved successfully.',
        'working_days' => $request->days,
        'next_step' => 'working_hours',
    ]);
}

public function saveWorkingHours(Request $request)
{
    $validator = Validator::make($request->all(), [
        'same_time_all_days' => ['required', 'boolean'],
        'working_hours' => ['required', 'array'],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    $restaurant = $request->user();

    $selectedDays = RestaurantWorkingDay::where('restaurant_id', $restaurant->id)
        ->pluck('day')
        ->toArray();

    if (empty($selectedDays)) {
        return response()->json([
            'success' => false,
            'message' => 'Please select working days first.',
        ], 400);
    }

    RestaurantWorkingHour::where('restaurant_id', $restaurant->id)->delete();

    if ($request->same_time_all_days) {

        if (!isset($request->working_hours['slots'])) {
            return response()->json([
                'success' => false,
                'message' => 'Slots are required.',
            ], 422);
        }

        foreach ($selectedDays as $day) {

            foreach ($request->working_hours['slots'] as $index => $slot) {

                RestaurantWorkingHour::create([
                    'restaurant_id' => $restaurant->id,
                    'day' => $day,
                    'slot' => $index + 1,
                    'open_time' => $slot['open_time'],
                    'close_time' => $slot['close_time'],
                ]);

            }
        }

    } else {

        foreach ($request->working_hours as $day => $slots) {

            if (!in_array($day, $selectedDays)) {
                continue;
            }

            foreach ($slots as $index => $slot) {

                RestaurantWorkingHour::create([
                    'restaurant_id' => $restaurant->id,
                    'day' => $day,
                    'slot' => $index + 1,
                    'open_time' => $slot['open_time'],
                    'close_time' => $slot['close_time'],
                ]);

            }
        }
    }

    $restaurant->update([
        'same_time_all_days' => $request->same_time_all_days,
        'onboarding_step' => 5,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Working hours saved successfully.',
        'same_time_all_days' => $restaurant->same_time_all_days,
        'next_step' => 'documents',
    ]);
}

public function getOutletTypes(Request $request)
{
    $outletTypes = RestaurantOutletType::where('is_active', true)
        ->orderBy('sort_order')
        ->get([
            'id',
            'name',
            'code',
            'description',
            'gst_rule'
        ]);

    return response()->json([
        'success' => true,
        'message' => 'Outlet types fetched successfully.',
        'data' => $outletTypes,
    ]);
}

public function saveDocuments(Request $request)
{
    $restaurant = $request->user();
    $existingDocument = RestaurantDocument::where('restaurant_id', $restaurant->id)->first();
    $outletType = RestaurantOutletType::find($request->input('outlet_type_id'));
    $requiresGst = $outletType?->code === 'category_1';

    $validator = Validator::make($request->all(), [
        'outlet_type_id' => 'required|exists:restaurant_outlet_types,id',
        'pan_number' => 'required|string|max:20',
        'gstin_number' => 'nullable|string|max:20',
        'upi_id' => 'required|string|max:100',
        'upi_holder_name' => 'required|string|max:100',
        'fssai_number' => 'required|string|max:50',
        'pan_image' => [
            Rule::requiredIf(!$existingDocument?->pan_image_path),
            'file',
            'mimes:jpg,jpeg,png,pdf',
            'max:5120',
        ],
        'gst_image' => [
            Rule::requiredIf($requiresGst && !$existingDocument?->gst_image_path),
            'nullable',
            'file',
            'mimes:jpg,jpeg,png,pdf',
            'max:5120',
        ],
        'fssai_image' => [
            Rule::requiredIf(!$existingDocument?->fssai_image_path),
            'file',
            'mimes:jpg,jpeg,png,pdf',
            'max:5120',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }

    $validated = $validator->validated();
    $uploadedPaths = [];

    try {
        foreach (['pan_image', 'gst_image', 'fssai_image'] as $field) {
            if ($request->hasFile($field)) {
                $path = $request->file($field)->store('restaurants/documents', 'public');
                if ($path === false) {
                    throw new \RuntimeException("Unable to store {$field}.");
                }

                $uploadedPaths[$field] = $path;
            }
        }

        DB::transaction(function () use ($restaurant, $validated, $existingDocument, $uploadedPaths) {
            RestaurantDocument::updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                ],
                [
                    'outlet_type_id' => $validated['outlet_type_id'],
                    'pan_number' => $validated['pan_number'],
                    'pan_image_path' => $uploadedPaths['pan_image'] ?? $existingDocument?->pan_image_path,
                    'gstin_number' => $validated['gstin_number'] ?? null,
                    'gst_image_path' => $uploadedPaths['gst_image'] ?? $existingDocument?->gst_image_path,
                    'upi_id' => $validated['upi_id'],
                    'upi_holder_name' => $validated['upi_holder_name'],
                    'fssai_number' => $validated['fssai_number'],
                    'fssai_image_path' => $uploadedPaths['fssai_image'] ?? $existingDocument?->fssai_image_path,

                    // Reset review when documents are submitted/re-submitted
                    'status' => 'pending',
                    'rejection_reason' => null,
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                ]
            );
        });
    } catch (\Throwable $exception) {
        if ($uploadedPaths) {
            Storage::disk('public')->delete(array_values($uploadedPaths));
        }

        throw $exception;
    }

    $restaurant->update([
        'onboarding_step' => 6,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Restaurant documents saved successfully.',
        'next_step' => 7,
    ]);
}

public function getDocuments(Request $request)
{
    $restaurant = $request->user();

    $document = RestaurantDocument::with('outletType')
        ->where('restaurant_id', $restaurant->id)
        ->first();

    if (!$document) {
        return response()->json([
            'success' => false,
            'message' => 'Restaurant documents not found.',
        ], 404);
    }

    return response()->json([
        'success' => true,
        'message' => 'Restaurant documents fetched successfully.',
      'data' => [
    'outlet_type' => [
        'id' => $document->outletType->id,
        'name' => $document->outletType->name,
    ],

    'pan_number' => $document->pan_number,
    'pan_image_url' => $this->documentUrl($document->pan_image_path),
    'gstin_number' => $document->gstin_number,
    'gst_image_url' => $this->documentUrl($document->gst_image_path),
    'upi_id' => $document->upi_id,
    'upi_holder_name' => $document->upi_holder_name,
    'fssai_number' => $document->fssai_number,
    'fssai_image_url' => $this->documentUrl($document->fssai_image_path),

    'status' => $document->status,
    'rejection_reason' => $document->rejection_reason,
],
    ]);
}

private function documentUrl(?string $path): ?string
{
    if (!$path) {
        return null;
    }

    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    return asset('storage/' . ltrim($path, '/'));
}

public function saveRestaurantName(Request $request)
{
    $validator = Validator::make($request->all(), [
        'restaurant_name' => 'required|string|max:255',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }

    $restaurant = $request->user();

    $restaurant->update([
        'restaurant_name' => $request->restaurant_name,
        'onboarding_step' => 7,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Restaurant name saved successfully.',
        'next_step' => 8,
        'data' => [
            'restaurant_name' => $restaurant->restaurant_name,
        ],
    ]);
}

public function getRestaurantName(Request $request)
{
    $restaurant = $request->user();

    return response()->json([
        'success' => true,
        'message' => 'Restaurant name fetched successfully.',
        'data' => [
            'restaurant_name' => $restaurant->restaurant_name,
        ],
    ]);
}

public function submitOnboarding(Request $request)
{
    $request->validate([
        'accepted' => 'required|accepted',
    ]);

    $restaurant = $request->user();

    // Validate onboarding data
    if (!RestaurantAddress::where('restaurant_id', $restaurant->id)->exists()) {
        return response()->json([
            'success' => false,
            'message' => 'Restaurant address is missing.',
        ], 422);
    }

    if (!RestaurantWorkingDay::where('restaurant_id', $restaurant->id)->exists()) {
        return response()->json([
            'success' => false,
            'message' => 'Working days are missing.',
        ], 422);
    }

    if (!RestaurantWorkingHour::where('restaurant_id', $restaurant->id)->exists()) {
        return response()->json([
            'success' => false,
            'message' => 'Working hours are missing.',
        ], 422);
    }

    if (!RestaurantDocument::where('restaurant_id', $restaurant->id)->exists()) {
        return response()->json([
            'success' => false,
            'message' => 'Restaurant documents are missing.',
        ], 422);
    }

    if (empty($restaurant->restaurant_name)) {
        return response()->json([
            'success' => false,
            'message' => 'Restaurant name is missing.',
        ], 422);
    }

    if (!RestaurantCategory::where('restaurant_id', $restaurant->id)->where('is_active', true)->exists()) {
        return response()->json([
            'success' => false,
            'message' => 'Please add at least one category.',
        ], 422);
    }

    if (!RestaurantMenuItem::where('restaurant_id', $restaurant->id)->where('is_active', true)->exists()) {
        return response()->json([
            'success' => false,
            'message' => 'Please add at least one menu item.',
        ], 422);
    }

    DB::beginTransaction();

    try {

        RestaurantPartnerContract::updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
            ],
            [
                'accepted' => true,
                'signed_at' => Carbon::now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );

  $restaurant->update([
    'status' => 'pending_approval',
    'onboarding_step' => 8,
    'is_profile_completed' => true,
]);

DB::commit();

return response()->json([
    'success' => true,
    'message' => 'Application submitted successfully. It is now pending admin approval.',
    'data' => [
        'status' => 'pending_approval',
    ],
]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => 'Something went wrong.',
            'error' => $e->getMessage(),
        ], 500);
    }
}

}