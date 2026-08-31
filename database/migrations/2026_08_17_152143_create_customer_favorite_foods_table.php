<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_favorite_foods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->foreignId('menu_item_id')
                ->constrained('restaurant_menu_items')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['customer_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_favorite_foods');
    }
};