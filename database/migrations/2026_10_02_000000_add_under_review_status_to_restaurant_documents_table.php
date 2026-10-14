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
            "ALTER TABLE restaurant_documents MODIFY status ENUM('pending', 'under_review', 'approved', 'rejected') NOT NULL DEFAULT 'pending'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('restaurant_documents')
            ->where('status', 'under_review')
            ->update(['status' => 'pending']);

        DB::statement(
            "ALTER TABLE restaurant_documents MODIFY status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'"
        );
    }
};
