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
        Schema::create('order_payments', function (Blueprint $table) {

            $table->id();

            // Order Relation
            $table->foreignId('delivery_order_id')
                  ->constrained('delivery_orders')
                  ->cascadeOnDelete();

            // Payment Details
            $table->enum('payment_method', [
                'COD',
                'ONLINE'
            ]);

            $table->enum('payment_status', [
                'PENDING',
                'PAID',
                'FAILED',
                'REFUNDED'
            ])->default('PENDING');

            // Amounts
            $table->decimal('order_amount', 10, 2);

            $table->decimal('delivery_fee', 10, 2)
                  ->default(0);

            $table->decimal('cod_amount', 10, 2)
                  ->default(0);

            // Payment Gateway
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
        Schema::dropIfExists('order_payments');
    }
};