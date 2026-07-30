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
    Schema::create('rider_otps', function (Blueprint $table) {
        $table->id();
        $table->string('phone', 15)->index();
        $table->string('otp', 6);
        $table->timestamp('expires_at');
        $table->timestamp('verified_at')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rider_otps');
    }
};
