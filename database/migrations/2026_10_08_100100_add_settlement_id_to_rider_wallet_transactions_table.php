<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_wallet_transactions', function (Blueprint $table) {
            // Weekly settlement cycle the entry belongs to
            $table->foreignId('settlement_id')
                ->nullable()
                ->after('rider_id')
                ->constrained('rider_weekly_settlements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rider_wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settlement_id');
        });
    }
};
