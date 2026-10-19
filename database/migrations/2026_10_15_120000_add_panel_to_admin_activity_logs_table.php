<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which panel the change was made from: admin or super_admin.
     * The admin panel only shows its own rows; the super admin sees both.
     */
    public function up(): void
    {
        Schema::table('admin_activity_logs', function (Blueprint $table) {
            $table->string('panel', 20)->default('admin')->after('admin_email');
            $table->index(['panel', 'id']);
        });

        DB::table('admin_activity_logs')
            ->where(function ($query) {
                $query->where('path', 'like', '%super-admin%')
                    ->orWhere('action', 'like', 'Super Admin%')
                    ->orWhere('source', 'like', 'Super Admin%');
            })
            ->update(['panel' => 'super_admin']);
    }

    public function down(): void
    {
        Schema::table('admin_activity_logs', function (Blueprint $table) {
            $table->dropIndex(['panel', 'id']);
            $table->dropColumn('panel');
        });
    }
};
