<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Admin Management page.
 *
 * Only real super admins manage these accounts. They sign in to the
 * separate /admin panel (POST /api/admin/login), not the super admin panel.
 */
class SuperAdminAdminController extends Controller
{
    private const AUDIT_MODULE = 'Admin Management';

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'last_login' => ['nullable', 'date'],
        ]);

        $admins = $this->panelAdmins()
            ->when($validated['search'] ?? null, function (Builder $query, string $search) {
                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['last_login'] ?? null, fn (Builder $query, string $date) => $query->whereDate('last_login_at', $date))
            ->latest()
            ->get()
            ->map(fn (Admin $admin) => $this->formatAdmin($admin));

        return response()->json(['admins' => $admins]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191', Rule::unique('admins', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $admin = Admin::create([
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'password' => $validated['password'], // hashed by the Admin model cast
            'role' => Admin::ROLE_ADMIN,
            'role_id' => null,
            'status' => 'active',
        ]);

        $this->audit($request, 'Admin Created', $admin, "Admin login {$admin->email} created.");

        return response()->json([
            'message' => "{$admin->name} can now sign in to the admin panel.",
            'admin' => $this->formatAdmin($admin),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $admin = $this->panelAdmins()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email', 'max:191', Rule::unique('admins', 'email')->ignore($admin->id)],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (isset($validated['name'])) {
            $validated['name'] = trim($validated['name']);
        }

        if (isset($validated['email'])) {
            $validated['email'] = strtolower(trim($validated['email']));
        }

        $admin->update($validated);

        // Deactivated admins are signed out immediately
        if ($admin->status !== 'active') {
            $admin->tokens()->delete();
        }

        $this->audit($request, 'Admin Updated', $admin, "Admin {$admin->email} updated.");

        return response()->json([
            'message' => "{$admin->name} updated successfully.",
            'admin' => $this->formatAdmin($admin),
        ]);
    }

    public function resetPassword(Request $request, int $id)
    {
        $admin = $this->panelAdmins()->findOrFail($id);

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $admin->update(['password' => $validated['password']]);
        $admin->tokens()->delete();

        $this->audit($request, 'Admin Password Reset', $admin, "Password reset for {$admin->email}.");

        return response()->json(['message' => "Password reset for {$admin->name}."]);
    }

    public function destroy(Request $request, int $id)
    {
        $admin = $this->panelAdmins()->findOrFail($id);

        $admin->tokens()->delete();
        $admin->delete();

        $this->audit($request, 'Admin Deleted', $admin, "Admin login {$admin->email} deleted.");

        return response()->json(['message' => "{$admin->name} deleted successfully."]);
    }

    /**
     * /admin panel accounts: not super admins, not Roles & Permissions accounts.
     */
    private function panelAdmins(): Builder
    {
        return Admin::query()
            ->where('role', '!=', Admin::ROLE_SUPER_ADMIN)
            ->whereNull('role_id');
    }

    private function audit(Request $request, string $action, Admin $admin, string $details): void
    {
        AdminActivityLog::record($request->user(), $request, [
            'action' => $action,
            'module' => self::AUDIT_MODULE,
            'target' => $admin->name,
            'source' => 'Admin Management',
            'details' => $details,
            'review_status' => 'Reviewed',
        ]);
    }

    private function formatAdmin(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'status' => $admin->status,
            'last_login_at' => $admin->last_login_at?->toIso8601String(),
            'created_at' => $admin->created_at?->toIso8601String(),
        ];
    }
}
