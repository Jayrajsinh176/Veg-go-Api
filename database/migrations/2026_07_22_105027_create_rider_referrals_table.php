<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_referrals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('referrer_rider_id')
                ->constrained('riders')
                ->cascadeOnDelete();

            $table->foreignId('referred_rider_id')
                ->constrained('riders')
                ->cascadeOnDelete();

            $table->string('referral_code');

            $table->integer('completed_orders')->default(0);

            $table->boolean('reward_credited')->default(false);

            $table->timestamp('rewarded_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_referrals');
    }
};