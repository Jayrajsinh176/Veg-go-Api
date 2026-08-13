<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')
                ->unique()
                ->constrained('restaurants')
                ->cascadeOnDelete();

            $table->boolean('order_alert_sound')->default(true);
            $table->boolean('vibration')->default(true);
            $table->boolean('auto_accept_orders')->default(false);

            $table->unsignedInteger('default_prep_time')->default(30);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_settings');
    }
};