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
        Schema::create('restaurant_offers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')
                ->constrained('restaurants')
                ->cascadeOnDelete();

            $table->foreignId('menu_item_id')
                ->constrained('restaurant_menu_items')
                ->cascadeOnDelete();

            $table->enum('discount_type', ['percentage', 'fixed']);
            $table->decimal('discount_value', 10, 2);

            $table->date('start_date');
            $table->date('end_date');

            // Manual on/off switch. Active / Scheduled / Expired is
            // derived from the dates at read time.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['menu_item_id', 'start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_offers');
    }
};
