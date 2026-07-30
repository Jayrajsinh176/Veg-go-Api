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
    Schema::create('order_items', function (Blueprint $table) {
        $table->id();

        // Parent Order
        $table->foreignId('delivery_order_id')
              ->constrained('delivery_orders')
              ->cascadeOnDelete();

        // Product Details
        $table->string('product_name');
        $table->integer('quantity')->default(1);
        $table->decimal('price', 10, 2);

        // Optional
        $table->text('special_instruction')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
