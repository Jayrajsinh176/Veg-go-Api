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
    Schema::create('riders', function (Blueprint $table) {
        $table->id();

        $table->string('name')->nullable();
        $table->string('phone', 15)->unique();
        $table->timestamp('phone_verified_at')->nullable();

        $table->enum('language', ['en', 'hi', 'gu'])
            ->default('en');

        $table->enum('status', [
            'pending',
            'approved',
            'rejected',
            'blocked'
        ])->default('pending');

        $table->boolean('onboarding_completed')->default(false);

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('riders');
    }
};
