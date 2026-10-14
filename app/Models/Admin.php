<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    // Accounts created from the Roles & Permissions page
    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'role_id',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function adminRole()
    {
        return $this->belongsTo(AdminRole::class, 'role_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Created from the Roles & Permissions page: super admin panel only,
     * never the separate /admin panel.
     */
    public function isRoleAdmin(): bool
    {
        return !$this->isSuperAdmin() && $this->role_id !== null;
    }

    /**
     * Can this account sign in to the super admin panel at all?
     */
    public function canAccessSuperAdminPanel(): bool
    {
        return $this->isSuperAdmin() || ($this->role_id && $this->adminRole);
    }

    /**
     * { moduleId: [actions] } granted to this account.
     */
    public function permissionMap(): array
    {
        if ($this->isSuperAdmin()) {
            return AdminRole::allPermissions();
        }

        return AdminRole::sanitizePermissions($this->adminRole?->permissions ?? []);
    }

    public function hasPermission(string $module, string $action = 'View'): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($action, $this->permissionMap()[$module] ?? [], true);
    }
}
