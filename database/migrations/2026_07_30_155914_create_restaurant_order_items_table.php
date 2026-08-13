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
        Schema::create('restaurant_order_items', function (Blueprint $table) {

    $table->id();

    // Restaurant Order
    $table->foreignId('order_id')
        ->constrained('restaurant_orders')
        ->cascadeOnDelete();

    // Menu Item
    $table->foreignId('menu_item_id')
        ->constrained('restaurant_menu_items')
        ->cascadeOnDelete();

    // Snapshot (Product details at the time of ordering)
    $table->string('menu_item_name');

    $table->string('variant_name')->nullable();

    $table->integer('quantity');

    $table->decimal('unit_price', 10, 2);

    $table->decimal('total_price', 10, 2);

    $table->text('special_instruction')->nullable();

    $table->timestamps();

});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_order_items');
    }
};
