<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use Illuminate\Http\Request;

class CustomerAddressController extends Controller
{
    /**
     * Add a new customer address.
     */
    public function store(Request $request)
    {
        $customer = $request->user();

        $validated = $request->validate([
            'label' => 'nullable|string|max:255',
            'receiver_name' => 'nullable|string|max:255',
            'receiver_phone' => [
                'nullable',
                'regex:/^[6-9]\d{9}$/',
            ],
            'address' => 'required|string',
            'landmark' => 'nullable|string|max:255',
            'pincode' => 'nullable|string|max:10',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_default' => 'nullable|boolean',
        ]);

        /*
         * If this is the customer's first address,
         * automatically make it the default address.
         */
        $hasAddresses = $customer->addresses()->exists();

        if (!$hasAddresses) {
            $validated['is_default'] = true;
        }

        /*
         * If the customer wants this address as default,
         * remove default from their previous address.
         */
        if (!empty($validated['is_default'])) {
            $customer->addresses()
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $address = $customer->addresses()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Address added successfully.',
            'data' => [
                'address' => $address,
            ],
        ], 201);
    }

    /**
 * Get customer's saved addresses.
 */
public function index(Request $request)
{
    $customer = $request->user();

    $addresses = $customer->addresses()
        ->orderByDesc('is_default')
        ->latest()
        ->get();

    return response()->json([
        'success' => true,
        'message' => 'Addresses fetched successfully.',
        'data' => [
            'addresses' => $addresses,
        ],
    ], 200);
}

/**
 * Set an address as the customer's default address.
 */
public function setDefault(Request $request, $id)
{
    $customer = $request->user();

    $address = $customer->addresses()->find($id);

    if (!$address) {
        return response()->json([
            'success' => false,
            'message' => 'Address not found.',
        ], 404);
    }

    // Remove default from all other addresses
    $customer->addresses()
        ->where('is_default', true)
        ->update(['is_default' => false]);

    // Set selected address as default
    $address->update([
        'is_default' => true,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Default address updated successfully.',
        'data' => [
            'address' => $address->fresh(),
        ],
    ], 200);
}

/**
 * Update an existing customer address.
 */
public function update(Request $request, $id)
{
    $customer = $request->user();

    $address = $customer->addresses()->find($id);

    if (!$address) {
        return response()->json([
            'success' => false,
            'message' => 'Address not found.',
        ], 404);
    }

    $validated = $request->validate([
        'label' => 'nullable|string|max:255',
        'receiver_name' => 'nullable|string|max:255',
        'receiver_phone' => [
            'nullable',
            'regex:/^[6-9]\d{9}$/',
        ],
        'address' => 'required|string',
        'landmark' => 'nullable|string|max:255',
        'pincode' => 'nullable|string|max:10',
        'latitude' => 'nullable|numeric|between:-90,90',
        'longitude' => 'nullable|numeric|between:-180,180',
        'is_default' => 'nullable|boolean',
    ]);

    if (!empty($validated['is_default'])) {
        $customer->addresses()
            ->where('is_default', true)
            ->where('id', '!=', $address->id)
            ->update(['is_default' => false]);
    }

    $address->update($validated);

    return response()->json([
        'success' => true,
        'message' => 'Address updated successfully.',
        'data' => [
            'address' => $address->fresh(),
        ],
    ], 200);
}

/**
 * Delete a customer address.
 */
public function destroy(Request $request, $id)
{
    $customer = $request->user();

    $address = $customer->addresses()->find($id);

    if (!$address) {
        return response()->json([
            'success' => false,
            'message' => 'Address not found.',
        ], 404);
    }

    $wasDefault = $address->is_default;

    $address->delete();

    // If the deleted address was default,
    // make the newest remaining address default.
    if ($wasDefault) {
        $newDefaultAddress = $customer->addresses()
            ->latest('id')
            ->first();

        if ($newDefaultAddress) {
            $newDefaultAddress->update([
                'is_default' => true,
            ]);
        }
    }

    return response()->json([
        'success' => true,
        'message' => 'Address deleted successfully.',
    ], 200);
}

}