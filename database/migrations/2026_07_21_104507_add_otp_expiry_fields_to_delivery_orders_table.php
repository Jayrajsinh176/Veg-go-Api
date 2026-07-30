<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {

            $table->timestamp('pickup_otp_expires_at')
                ->nullable()
                ->after('pickup_otp');

            $table->timestamp('delivery_otp_expires_at')
                ->nullable()
                ->after('delivery_otp');

        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {

            $table->dropColumn([
                'pickup_otp_expires_at',
                'delivery_otp_expires_at',
            ]);

        });
    }
};