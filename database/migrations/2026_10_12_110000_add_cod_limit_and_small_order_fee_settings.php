<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add fields to platform_settings
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->decimal('max_cod_order_amount', 10, 2)->default(2000.00)->after('cod_enabled');
            $table->decimal('small_order_threshold', 10, 2)->default(250.00)->after('platform_fee');
            $table->decimal('small_order_fee', 10, 2)->default(10.00)->after('small_order_threshold');
        });

        // Update default row 1 in platform_settings if it exists
        DB::table('platform_settings')->where('id', 1)->update([
            'max_cod_order_amount' => 2000.00,
            'small_order_threshold' => 250.00,
            'small_order_fee' => 10.00,
        ]);

        // 2. Add small_order_fee to customer_carts
        if (!Schema::hasColumn('customer_carts', 'small_order_fee')) {
            Schema::table('customer_carts', function (Blueprint $table) {
                $table->decimal('small_order_fee', 10, 2)->default(0.00)->after('platform_fee');
            });
        }

        // 3. Add small_order_fee to restaurant_orders
        if (!Schema::hasColumn('restaurant_orders', 'small_order_fee')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                $table->decimal('small_order_fee', 10, 2)->default(0.00)->after('platform_fee');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['max_cod_order_amount', 'small_order_threshold', 'small_order_fee']);
        });

        if (Schema::hasColumn('customer_carts', 'small_order_fee')) {
            Schema::table('customer_carts', function (Blueprint $table) {
                $table->dropColumn('small_order_fee');
            });
        }

        if (Schema::hasColumn('restaurant_orders', 'small_order_fee')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                $table->dropColumn('small_order_fee');
            });
        }
    }
};

