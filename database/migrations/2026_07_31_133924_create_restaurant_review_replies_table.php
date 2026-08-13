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
    Schema::create('restaurant_review_replies', function (Blueprint $table) {
        $table->id();

        $table->foreignId('restaurant_review_id')
            ->constrained('restaurant_reviews')
            ->cascadeOnDelete();

        $table->foreignId('restaurant_id')
            ->constrained('restaurants')
            ->cascadeOnDelete();

        $table->text('reply');

        $table->timestamps();

        $table->unique('restaurant_review_id');
    });
}

public function down(): void
{
    Schema::dropIfExists('restaurant_review_replies');
}
};
