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
    Schema::create('rider_upi_details', function (Blueprint $table) {

        $table->id();

        $table->foreignId('rider_id')
            ->constrained('riders')
            ->cascadeOnDelete()
            ->unique();

        $table->string('holder_name');

        $table->string('upi_id')->unique();

        $table->boolean('is_verified')->default(false);

        $table->timestamp('verified_at')->nullable();

        $table->unsignedBigInteger('verified_by')->nullable();

        $table->text('rejection_reason')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
   public function down(): void
{
    Schema::dropIfExists('rider_upi_details');
}
};
