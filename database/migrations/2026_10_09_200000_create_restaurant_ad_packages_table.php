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
        Schema::create('restaurant_ad_packages', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // Stable key used by code: HOME_SCREEN_SHOWCASE, FOOD_ITEM_PROMOTION, ...
            $table->string('code', 50)->unique();

            $table->text('description')->nullable();

            // Where the ad shows: home, food_item, checkout, restaurant
            $table->string('placement', 20);

            $table->boolean('requires_image')->default(true);

            $table->boolean('is_active')->default(true);

            $table->unsignedInteger('display_order')->default(0);

            $table->timestamps();
        });

        Schema::create('restaurant_ad_package_durations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('package_id')
                ->constrained('restaurant_ad_packages')
                ->cascadeOnDelete();

            $table->unsignedInteger('duration_days');

            // Price before GST
            $table->decimal('price', 10, 2);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['package_id', 'duration_days']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_ad_package_durations');
        Schema::dropIfExists('restaurant_ad_packages');
    }
};
