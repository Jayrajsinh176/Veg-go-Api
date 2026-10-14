<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE restaurants MODIFY status ENUM('draft', 'pending_approval', 'approved', 'rejected', 'inactive', 'suspended') NOT NULL DEFAULT 'draft'"
        );

        Schema::table('restaurants', function (Blueprint $table) {
            // Status to restore when admin lifts the suspension
            $table->string('status_before_suspension', 30)
                ->nullable()
                ->after('status');

            $table->timestamp('suspended_at')
                ->nullable()
                ->after('status_before_suspension');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('restaurants')
            ->where('status', 'suspended')
            ->update([
                'status' => DB::raw("COALESCE(status_before_suspension, 'approved')"),
            ]);

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['status_before_suspension', 'suspended_at']);
        });

        DB::statement(
            "ALTER TABLE restaurants MODIFY status ENUM('draft', 'pending_approval', 'approved', 'rejected', 'inactive') NOT NULL DEFAULT 'draft'"
        );
    }
};
