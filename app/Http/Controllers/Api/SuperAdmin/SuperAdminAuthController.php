<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SuperAdminAuthController extends Controller
{
    private const TOKEN_NAME = 'super-admin-token';

    /**
     * Super Admin Login
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember_me' => ['sometimes', 'boolean'],
        ]);

        $admin = Admin::where('email', strtolower(trim($validated['email'])))->first();

        if (!$admin || !Hash::check($validated['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        if (!$admin->canAccessSuperAdminPanel()) {
            return response()->json([
                'message' => 'This account does not have access to the admin panel.',
            ], 403);
        }

        if ($admin->status !== 'active') {
            return response()->json([
                'message' => 'Your account is inactive.',
            ], 403);
        }

        // Remove old super admin tokens only (keeps any admin panel session intact)
        $admin->tokens()->where('name', self::TOKEN_NAME)->delete();

        // "Remember this device" keeps the token valid for 30 days, otherwise 1 day
        $expiresAt = !empty($validated['remember_me'])
            ? now()->addDays(30)
            : now()->addDay();

        $token = $admin->createToken(self::TOKEN_NAME, ['super-admin'], $expiresAt)->plainTextToken;

        $admin->update([
            'last_login_at' => now(),
        ]);

        $roleName = $admin->isSuperAdmin() ? 'Super administrator' : $admin->adminRole->name;

        AdminActivityLog::record($admin, $request, [
            'action' => 'Super Admin Login',
            'module' => 'Security',
            'source' => 'Super Admin Authentication',
            'change_summary' => 'Session: Signed Out → Signed In',
            'details' => "{$roleName} signed in to the super admin panel.",
            'review_status' => 'Reviewed',
        ]);

        return response()->json([
            'message' => 'Super admin login successful.',
            'token' => $token,
            'expires_at' => $expiresAt,
            'admin' => $this->formatAdmin($admin),
        ]);
    }

    /**
     * Get authenticated super admin
     */
    public function me(Request $request)
    {
        return response()->json([
            'message' => 'Super admin details fetched successfully.',
            'admin' => $this->formatAdmin($request->user()),
        ]);
    }

    /**
     * Super Admin Logout
     */
    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Super admin logout successful.',
        ]);
    }

    private function formatAdmin(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'is_super_admin' => $admin->isSuperAdmin(),
            'role_id' => $admin->role_id,
            'role_name' => $admin->isSuperAdmin() ? 'Super Admin' : $admin->adminRole?->name,
            'permissions' => $admin->permissionMap(),
            'status' => $admin->status,
            'last_login_at' => $admin->last_login_at,
        ];
    }
}
