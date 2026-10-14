<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Drop the placeholder roles seeded by create_admin_roles_table.
     * Roles that already have a login assigned are kept.
     */
    public function up(): void
    {
        DB::table('admin_roles')
            ->where('is_built_in', true)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('admins')
                    ->whereColumn('admins.role_id', 'admin_roles.id');
            })
            ->delete();
    }

    public function down(): void
    {
        // Placeholder roles are not restored
    }
};
