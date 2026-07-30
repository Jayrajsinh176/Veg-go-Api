<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {

            // Restaurant Pickup Verification
            $table->string('pickup_otp', 6)->nullable()->after('status');
            $table->timestamp('pickup_verified_at')->nullable()->after('pickup_otp');

            // Customer Delivery Verification
            $table->string('delivery_otp', 6)->nullable()->after('pickup_verified_at');
            $table->timestamp('delivery_verified_at')->nullable()->after('delivery_otp');

        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {

            $table->dropColumn([
                'pickup_otp',
                'pickup_verified_at',
                'delivery_otp',
                'delivery_verified_at',
            ]);

        });
    }
};