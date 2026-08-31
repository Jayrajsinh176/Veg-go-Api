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
      Schema::create('city_areas', function (Blueprint $table) {
    $table->id();

    $table->foreignId('city_id')
        ->constrained('cities')
        ->cascadeOnDelete();

    $table->string('name');

    $table->boolean('is_active')->default(true);

    $table->timestamps();

    $table->index(['city_id', 'is_active']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('city_areas');
    }
};
