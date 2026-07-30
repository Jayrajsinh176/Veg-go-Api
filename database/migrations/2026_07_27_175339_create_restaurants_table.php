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
      Schema::create('restaurants', function (Blueprint $table) {
    $table->id();

    $table->string('mobile', 10)->unique();

    $table->enum('status', [
        'draft',
        'pending_approval',
        'approved',
        'rejected',
        'inactive'
    ])->default('draft');

    $table->unsignedTinyInteger('onboarding_step')->default(1);

    $table->boolean('is_profile_completed')->default(false);

    $table->rememberToken();

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
