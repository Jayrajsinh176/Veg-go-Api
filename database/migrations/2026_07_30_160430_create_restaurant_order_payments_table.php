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
        Schema::create('restaurant_order_payments', function (Blueprint $table) {

            $table->id();

            // Restaurant Order
            $table->foreignId('order_id')
                ->constrained('restaurant_orders')
                ->cascadeOnDelete();

            // Payment Method
            $table->enum('payment_method', [
                'COD',
                'ONLINE',
                'WALLET'
            ]);

            // Payment Status
            $table->enum('payment_status', [
                'PENDING',
                'PAID',
                'FAILED',
                'REFUNDED'
            ])->default('PENDING');

            // Amount Details
            $table->decimal('order_amount', 10, 2);

            $table->decimal('delivery_fee', 10, 2)
                ->default(0);

            $table->decimal('packing_charge', 10, 2)
                ->default(0);

            $table->decimal('tax', 10, 2)
                ->default(0);

            $table->decimal('discount', 10, 2)
                ->default(0);

            $table->decimal('total_amount', 10, 2);

            // Online Payment Details
            $table->string('transaction_id')
                ->nullable();

            $table->string('payment_gateway')
                ->nullable();

            $table->timestamp('paid_at')
                ->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_order_payments');
    }
};