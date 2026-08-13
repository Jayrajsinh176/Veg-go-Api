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
        Schema::create('order_status_histories', function (Blueprint $table) {

    $table->id();

    // Restaurant Order
    $table->foreignId('order_id')
        ->constrained('restaurant_orders')
        ->cascadeOnDelete();

    // Status
    $table->enum('status', [
        'pending',
        'accepted',
        'preparing',
        'ready_for_pickup',
        'picked_up',
        'delivered',
        'cancelled'
    ]);

    // Who changed the status
    $table->enum('changed_by', [
        'restaurant',
        'rider',
        'system',
        'admin'
    ]);

    $table->text('remarks')->nullable();

    $table->timestamps();

});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
