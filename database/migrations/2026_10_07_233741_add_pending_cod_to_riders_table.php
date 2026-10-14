<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riders', function (Blueprint $table) {
            $table->decimal('pending_cod', 12, 2)
                ->default(0)
                ->after('wallet_balance');
        });
    }

    public function down(): void
    {
        Schema::table('riders', function (Blueprint $table) {
            $table->dropColumn('pending_cod');
        });
    }
};