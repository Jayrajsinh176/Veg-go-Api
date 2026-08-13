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
    Schema::table('restaurant_orders', function (Blueprint $table) {

        $table->string('pickup_otp', 6)
              ->nullable()
              ->after('rider_id');

        $table->timestamp('pickup_otp_generated_at')
              ->nullable()
              ->after('pickup_otp');

        $table->timestamp('pickup_verified_at')
              ->nullable()
              ->after('pickup_otp_generated_at');

    });
}

public function down(): void
{
    Schema::table('restaurant_orders', function (Blueprint $table) {

        $table->dropColumn([
            'pickup_otp',
            'pickup_otp_generated_at',
            'pickup_verified_at',
        ]);

    });
}
};
