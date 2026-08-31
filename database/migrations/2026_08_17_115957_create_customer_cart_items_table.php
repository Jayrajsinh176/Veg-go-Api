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
        Schema::create('customer_cart_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cart_id')
                ->constrained('customer_carts')
                ->cascadeOnDelete();

            $table->foreignId('menu_item_id')
                ->constrained('restaurant_menu_items')
                ->cascadeOnDelete();

            $table->string('menu_item_name');

            $table->integer('quantity')->default(1);

            $table->decimal('unit_price', 10, 2);

            $table->decimal('total_price', 10, 2);

            $table->text('special_instruction')->nullable();

            $table->timestamps();

            // Same menu item can exist only once in a cart
            $table->unique(['cart_id', 'menu_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_cart_items');
    }
};