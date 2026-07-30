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
    Schema::create('rider_kycs', function (Blueprint $table) {
        $table->id();

        $table->foreignId('rider_id')
            ->unique()
            ->constrained('riders')
            ->cascadeOnDelete();

        $table->text('aadhaar_number')->nullable();
        $table->string('aadhaar_last_four', 4)->nullable();
        $table->boolean('aadhaar_verified')->default(false);

        $table->text('pan_number')->nullable();
        $table->boolean('has_pan')->default(true);
        $table->boolean('pan_verified')->default(false);

        $table->string('selfie')->nullable();

        $table->enum('status', [
            'pending',
            'verified',
            'rejected',
        ])->default('pending');

        $table->text('rejection_reason')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rider_kycs');
    }
};
