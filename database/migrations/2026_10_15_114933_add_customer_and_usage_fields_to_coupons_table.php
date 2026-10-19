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
        Schema::table('coupons', function (Blueprint $table) {
            $table->foreignId('customer_id')
                ->nullable()
                ->after('id')
                ->constrained('customers')
                ->nullOnDelete();

            $table->foreignId('order_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('restaurant_orders')
                ->nullOnDelete();

            $table->boolean('is_single_use')->default(false)->after('max_discount_amount');
            $table->boolean('is_compensation')->default(false)->after('is_single_use');
            $table->timestamp('used_at')->nullable()->after('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['order_id']);
            $table->dropColumn(['customer_id', 'order_id', 'is_single_use', 'is_compensation', 'used_at']);
        });
    }
};
