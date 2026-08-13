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
    Schema::create('restaurant_orders', function (Blueprint $table) {

    $table->id();

    // Restaurant
    $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

    // Customer
   $table->unsignedBigInteger('customer_id')->nullable();

    // Delivery Address
    $table->unsignedBigInteger('delivery_address_id')->nullable();
    // Rider
    $table->foreignId('rider_id')->nullable()->constrained()->nullOnDelete();

    // Order Details
    $table->string('order_number')->unique();

    $table->decimal('subtotal', 10, 2)->default(0);

    $table->decimal('delivery_charge', 10, 2)->default(0);

    $table->decimal('packing_charge', 10, 2)->default(0);

    $table->decimal('tax', 10, 2)->default(0);

    $table->decimal('discount', 10, 2)->default(0);

    $table->decimal('total_amount', 10, 2)->default(0);

    // Payment
    $table->enum('payment_method', [
        'cash',
        'online',
        'wallet'
    ]);

    $table->enum('payment_status', [
        'pending',
        'paid',
        'failed',
        'refunded'
    ])->default('pending');

    // Order Status
    $table->enum('order_status', [
        'pending',
        'accepted',
        'preparing',
        'ready_for_pickup',
        'picked_up',
        'delivered',
        'cancelled'
    ])->default('pending');

    // Timeline
    $table->timestamp('placed_at')->nullable();

    $table->timestamp('accepted_at')->nullable();

    $table->timestamp('preparing_at')->nullable();

    $table->timestamp('ready_for_pickup_at')->nullable();

    $table->timestamp('picked_up_at')->nullable();

    $table->timestamp('delivered_at')->nullable();

    $table->timestamp('cancelled_at')->nullable();

    // Extra
    $table->text('cancel_reason')->nullable();

    $table->text('notes')->nullable();

    $table->timestamps();

});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_orders');
    }
};
