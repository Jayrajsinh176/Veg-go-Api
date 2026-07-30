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
    Schema::create('work_zones', function (Blueprint $table) {
        $table->id();

        $table->foreignId('city_id')
            ->constrained('cities')
            ->cascadeOnDelete();

        $table->string('name');

        $table->decimal('minimum_guarantee', 10, 2)
            ->default(0);

        $table->decimal('weekly_earning_upto', 10, 2)
            ->nullable();

        $table->boolean('is_best_zone')
            ->default(false);

        $table->boolean('is_active')
            ->default(true);

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_zones');
    }
};
