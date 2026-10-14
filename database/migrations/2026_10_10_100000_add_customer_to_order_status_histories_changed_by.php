<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE order_status_histories MODIFY changed_by ENUM('restaurant', 'rider', 'system', 'admin', 'customer') NOT NULL"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('order_status_histories')
            ->where('changed_by', 'customer')
            ->update(['changed_by' => 'system']);

        DB::statement(
            "ALTER TABLE order_status_histories MODIFY changed_by ENUM('restaurant', 'rider', 'system', 'admin') NOT NULL"
        );
    }
};
