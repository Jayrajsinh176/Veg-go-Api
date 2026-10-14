<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\AdminRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Roles & Permissions page.
 *
 * A role holds the module/action matrix; admins assigned to it (role_id)
 * sign in through the super admin login and only see those modules.
 */
class SuperAdminRoleController extends Controller
{
    private const AUDIT_MODULE = 'Roles & Permissions';

    public function index()
    {
        $roles = AdminRole::with(['admins' => fn ($query) => $query->orderBy('name')])
            ->orderByDesc('is_built_in')
            ->orderBy('name')
            ->get()
            ->map(fn (AdminRole $role) => $this->formatRole($role));

        $superAdmins = Admin::where('role', Admin::ROLE_SUPER_ADMIN)->orderBy('name')->get();

        // Super Admin is not a stored role: it always has every permission
        $roles->prepend([
            'id' => 'super-admin',
            'name' => 'Super Admin',
            'description' => 'Complete access to the VegGo Super Admin Panel.',
            'permissions' => AdminRole::allPermissions(),
            'is_built_in' => true,
            'is_super_admin' => true,
            'administrators_count' => $superAdmins->count(),
            'admins' => $superAdmins->map(fn (Admin $admin) => $this->formatAdmin($admin))->values(),
            'created_at' => null,
        ]);

        return response()->json([
            'roles' => $roles->values(),
            'modules' => config('admin_permissions.modules'),
            'actions' => config('admin_permissions.actions'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('admin_roles', 'name'), Rule::notIn(['Super Admin', 'super admin'])],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            // Login for the role's first administrator (optional when duplicating)
            'admin_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'required_with:password', 'email', 'max:191', Rule::unique('admins', 'email')],
            'password' => ['nullable', 'required_with:email', 'confirmed', Password::min(8)],
        ]);

        $role = DB::transaction(function () use ($validated) {
            $role = AdminRole::create([
                'name' => trim($validated['name']),
                'description' => $validated['description'] ?? null,
                'permissions' => AdminRole::sanitizePermissions($validated['permissions'] ?? []),
                'is_built_in' => false,
            ]);

            if (!empty($validated['email'])) {
                $this->createRoleAdmin($role, [
                    'name' => $validated['admin_name'] ?? null,
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                ]);
            }

            return $role;
        });

        $role->load('admins');

        $this->audit($request, 'Role Created', $role->name, sprintf(
            'Role created with %d permissions%s.',
            $this->countPermissions($role->permissions),
            $role->admins->isNotEmpty() ? ' and login ' . $role->admins->first()->email : ''
        ));

        return response()->json([
            'message' => "{$role->name} role created successfully.",
            'role' => $this->formatRole($role),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $role = AdminRole::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('admin_roles', 'name')->ignore($role->id), Rule::notIn(['Super Admin', 'super admin'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
        ]);

        $oldCount = $this->countPermissions($role->permissions);

        if (array_key_exists('permissions', $validated)) {
            $validated['permissions'] = AdminRole::sanitizePermissions($validated['permissions']);
        }

        $role->update($validated);
        $role->load('admins');

        $this->audit($request, 'Permission Update', $role->name, sprintf(
            '%d permissions changed to %d active permissions.',
            $oldCount,
            $this->countPermissions($role->permissions)
        ));

        return response()->json([
            'message' => "{$role->name} permissions saved successfully.",
            'role' => $this->formatRole($role),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $role = AdminRole::with('admins')->findOrFail($id);

        if ($role->is_built_in) {
            return response()->json(['message' => 'Built-in roles cannot be deleted.'], 422);
        }

        DB::transaction(function () use ($role) {
            // Its administrators lose panel access immediately
            foreach ($role->admins as $admin) {
                $admin->tokens()->delete();
                $admin->update(['status' => 'inactive']);
            }

            $role->delete();
        });

        $this->audit($request, 'Role Deleted', $role->name, sprintf(
            'Custom role deleted; %d administrator account(s) deactivated.',
            $role->admins->count()
        ));

        return response()->json(['message' => "{$role->name} deleted successfully."]);
    }

    public function storeAdmin(Request $request, int $id)
    {
        $role = AdminRole::findOrFail($id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191', Rule::unique('admins', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $admin = $this->createRoleAdmin($role, $validated);

        $this->audit($request, 'Administrator Added', $role->name, "Login {$admin->email} assigned to the role.");

        return response()->json([
            'message' => "{$admin->email} can now sign in as {$role->name}.",
            'admin' => $this->formatAdmin($admin),
        ], 201);
    }

    public function destroyAdmin(Request $request, int $id, int $adminId)
    {
        $role = AdminRole::findOrFail($id);
        $admin = $role->admins()->findOrFail($adminId);

        $admin->tokens()->delete();
        $admin->delete();

        $this->audit($request, 'Administrator Removed', $role->name, "Login {$admin->email} removed from the role.");

        return response()->json(['message' => "{$admin->email} removed successfully."]);
    }

    public function auditHistory()
    {
        $entries = AdminActivityLog::where('module', self::AUDIT_MODULE)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (AdminActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'role' => $log->target,
                'details' => $log->details,
                'admin' => $log->admin_name ?: 'Super Admin',
                'date' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json(['entries' => $entries]);
    }

    private function audit(Request $request, string $action, string $roleName, string $details): void
    {
        AdminActivityLog::record($request->user(), $request, [
            'action' => $action,
            'module' => self::AUDIT_MODULE,
            'target' => $roleName,
            'source' => 'Roles & Permissions',
            'details' => $details,
            'review_status' => 'Reviewed',
        ]);
    }

    private function createRoleAdmin(AdminRole $role, array $data): Admin
    {
        $email = strtolower(trim($data['email']));

        return Admin::create([
            'name' => trim($data['name'] ?? '') ?: $role->name,
            'email' => $email,
            'password' => $data['password'], // hashed by the Admin model cast
            'role' => Admin::ROLE_ADMIN,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    private function formatRole(AdminRole $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => AdminRole::sanitizePermissions($role->permissions ?? []),
            'is_built_in' => $role->is_built_in,
            'is_super_admin' => false,
            'administrators_count' => $role->admins->count(),
            'admins' => $role->admins->map(fn (Admin $admin) => $this->formatAdmin($admin))->values(),
            'created_at' => $role->created_at?->toIso8601String(),
        ];
    }

    private function formatAdmin(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'status' => $admin->status,
            'last_login_at' => $admin->last_login_at?->toIso8601String(),
        ];
    }

    private function countPermissions(?array $permissions): int
    {
        return array_sum(array_map('count', AdminRole::sanitizePermissions($permissions ?? [])));
    }
}
