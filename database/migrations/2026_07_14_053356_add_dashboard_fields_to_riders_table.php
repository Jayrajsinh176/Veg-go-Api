<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('riders', function (Blueprint $table) {
        $table->boolean('is_online')
            ->default(false)
            ->after('status');

        $table->decimal('wallet_balance', 12, 2)
            ->default(0)
            ->after('is_online');

        $table->timestamp('last_online_at')
            ->nullable()
            ->after('wallet_balance');
    });
}

public function down(): void
{
    Schema::table('riders', function (Blueprint $table) {
        $table->dropColumn([
            'is_online',
            'wallet_balance',
            'last_online_at',
        ]);
    });
}
};
