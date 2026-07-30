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
    Schema::table('delivery_orders', function (Blueprint $table) {

        $table->timestamp('accepted_at')->nullable()->after('status');
        $table->timestamp('arrived_at')->nullable()->after('accepted_at');
        $table->timestamp('picked_up_at')->nullable()->after('arrived_at');
        $table->timestamp('delivered_at')->nullable()->after('picked_up_at');

    });
}

    /**
     * Reverse the migrations.
     */
public function down(): void
{
    Schema::table('delivery_orders', function (Blueprint $table) {

        $table->dropColumn([
            'accepted_at',
            'arrived_at',
            'picked_up_at',
            'delivered_at',
        ]);

    });
}
};
