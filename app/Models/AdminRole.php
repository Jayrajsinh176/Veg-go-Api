<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminRole extends Model
{
    protected $fillable = [
        'name',
        'description',
        'permissions',
        'is_built_in',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_built_in' => 'boolean',
        ];
    }

    public function admins()
    {
        return $this->hasMany(Admin::class, 'role_id');
    }

    /**
     * Keep only known modules/actions. Any granted action implies View,
     * otherwise the module would be unreachable from the sidebar.
     */
    public static function sanitizePermissions(?array $permissions): array
    {
        $modules = array_keys(config('admin_permissions.modules'));
        $actions = config('admin_permissions.actions');
        $clean = [];

        foreach ($modules as $module) {
            $granted = $permissions[$module] ?? [];

            // Accept both ['View', 'Edit'] and { View: true, Edit: false }
            if (is_array($granted) && !array_is_list($granted)) {
                $granted = array_keys(array_filter($granted, fn ($value) => $value === true));
            }

            $granted = array_values(array_intersect($actions, (array) $granted));

            if ($granted && !in_array('View', $granted, true)) {
                array_unshift($granted, 'View');
            }

            if ($granted) {
                $clean[$module] = $granted;
            }
        }

        return $clean;
    }

    public static function allPermissions(): array
    {
        $actions = config('admin_permissions.actions');

        return array_map(fn () => $actions, config('admin_permissions.modules'));
    }
}
