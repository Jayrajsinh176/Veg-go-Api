<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_wallet_transactions', function (Blueprint $table) {
            // Weekly settlement cycle the entry belongs to
            $table->foreignId('settlement_id')
                ->nullable()
                ->after('restaurant_id')
                ->constrained('restaurant_weekly_settlements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settlement_id');
        });
    }
};
