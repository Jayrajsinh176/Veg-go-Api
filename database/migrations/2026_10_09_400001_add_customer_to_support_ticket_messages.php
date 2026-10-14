<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `support_ticket_messages` MODIFY COLUMN `ticket_type` ENUM('rider', 'restaurant', 'customer') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `support_ticket_messages` MODIFY COLUMN `ticket_type` ENUM('rider', 'restaurant') NOT NULL");
    }
};

