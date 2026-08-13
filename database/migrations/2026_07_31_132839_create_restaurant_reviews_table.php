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
    Schema::create('restaurant_reviews', function (Blueprint $table) {
        $table->id();

        $table->foreignId('restaurant_id')
            ->constrained('restaurants')
            ->cascadeOnDelete();

        $table->foreignId('restaurant_order_id')
            ->constrained('restaurant_orders')
            ->cascadeOnDelete();

        // Customer backend is not built yet
        $table->unsignedBigInteger('customer_id')->nullable();

        $table->tinyInteger('rating');

        $table->text('review')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_reviews');
    }
};
