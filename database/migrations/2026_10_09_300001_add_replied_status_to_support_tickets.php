<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE rider_support_tickets MODIFY status ENUM('pending','in_progress','replied','resolved','closed') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE restaurant_support_tickets MODIFY status ENUM('pending','in_progress','replied','resolved','closed') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE rider_support_tickets MODIFY status ENUM('pending','in_progress','resolved','closed') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE restaurant_support_tickets MODIFY status ENUM('pending','in_progress','resolved','closed') NOT NULL DEFAULT 'pending'");
    }
};
