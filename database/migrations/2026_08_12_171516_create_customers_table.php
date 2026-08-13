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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // Customer mobile number used for OTP login
            $table->string('phone', 10)->unique();

            // Set after successful OTP verification
            $table->timestamp('phone_verified_at')->nullable();

            // Customer profile information
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};