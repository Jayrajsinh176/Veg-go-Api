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

            // Restaurant Details
            $table->text('restaurant_address')->nullable()->after('restaurant_name');
            $table->string('restaurant_phone', 20)->nullable()->after('restaurant_address');

            // Customer Details
            $table->string('customer_name')->nullable()->after('restaurant_phone');
            $table->string('customer_phone', 20)->nullable()->after('customer_name');
            $table->text('delivery_address')->nullable()->after('customer_phone');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {

            $table->dropColumn([
                'restaurant_address',
                'restaurant_phone',
                'customer_name',
                'customer_phone',
                'delivery_address',
            ]);

        });
    }
};