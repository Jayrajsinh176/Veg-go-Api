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
    Schema::create('rider_delivery_addresses', function (Blueprint $table) {
        $table->id();

        $table->foreignId('rider_id')
            ->unique()
            ->constrained('riders')
            ->cascadeOnDelete();

        $table->string('house_flat');
        $table->string('locality');
        $table->string('landmark')->nullable();
        $table->string('pincode', 6);
        $table->string('city');
        $table->string('state');

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rider_delivery_addresses');
    }
};
