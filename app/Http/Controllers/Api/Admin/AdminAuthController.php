<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    /**
     * Admin Login
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = Admin::where('email', $validated['email'])->first();

        if (!$admin || !Hash::check($validated['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Role accounts sign in through the super admin panel only
        if ($admin->isRoleAdmin()) {
            return response()->json([
                'message' => 'This account does not have access to the admin panel.',
            ], 403);
        }

        if ($admin->status !== 'active') {
            return response()->json([
                'message' => 'Your admin account is inactive.',
            ], 403);
        }

        // Remove old tokens
        $admin->tokens()->delete();

        // Create new token
        $token = $admin->createToken('admin-token')->plainTextToken;

        // Update last login
        $admin->update([
            'last_login_at' => now(),
        ]);

        AdminActivityLog::record($admin, $request, [
            'action' => 'Admin Login',
            'module' => 'Security',
            'source' => 'Admin Authentication',
            'change_summary' => 'Session: Signed Out → Signed In',
            'details' => 'Administrator signed in to the admin panel.',
            'review_status' => 'Reviewed',
        ]);

        return response()->json([
            'message' => 'Admin login successful.',
            'token' => $token,
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'status' => $admin->status,
                'last_login_at' => $admin->last_login_at,
            ],
        ]);
    }

    /**
     * Get authenticated admin
     */
    public function me(Request $request)
    {
        $admin = $request->user();

        return response()->json([
            'message' => 'Admin details fetched successfully.',
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'status' => $admin->status,
                'last_login_at' => $admin->last_login_at,
            ],
        ]);
    }

    /**
     * Admin Logout
     */
    public function logout(Request $request)
    {
        $admin = $request->user();

        if ($admin) {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'message' => 'Admin logout successful.',
        ]);
    }
}