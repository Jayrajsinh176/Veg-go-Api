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
        Schema::create('customer_carts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->foreignId('restaurant_id')
                ->constrained('restaurants')
                ->cascadeOnDelete();

            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('delivery_charge', 10, 2)->default(0);
            $table->decimal('packing_charge', 10, 2)->default(0);
            $table->decimal('platform_fee', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->string('coupon_code', 100)->nullable();
            $table->enum('discount_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('discount_value', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);

            $table->timestamps();

            // One active cart per customer
            $table->unique('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_carts');
    }
};