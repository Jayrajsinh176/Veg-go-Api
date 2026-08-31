<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CustomerSettingsController extends Controller
{
    /**
     * Update customer's push notification setting.
     */
    public function updateNotifications(Request $request)
    {
        $customer = $request->user();

        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $customer->update([
            'push_notifications_enabled' => $validated['enabled'],
        ]);

        return response()->json([
            'success' => true,
            'message' => $validated['enabled']
                ? 'Push notifications enabled successfully.'
                : 'Push notifications disabled successfully.',
            'data' => [
                'push_notifications_enabled' => (bool) $customer->push_notifications_enabled,
            ],
        ], 200);
    }

    public function show(Request $request)
{
    $customer = $request->user();

    return response()->json([
        'success' => true,
        'message' => 'Settings fetched successfully.',
        'data' => [
            'push_notifications_enabled' => (bool) $customer->push_notifications_enabled,
        ],
    ], 200);
}

}