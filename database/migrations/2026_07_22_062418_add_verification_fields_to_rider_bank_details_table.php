<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_bank_details', function (Blueprint $table) {

            $table->timestamp('verified_at')
                ->nullable()
                ->after('is_verified');

            $table->unsignedBigInteger('verified_by')
                ->nullable()
                ->after('verified_at');

            $table->text('rejection_reason')
                ->nullable()
                ->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('rider_bank_details', function (Blueprint $table) {

            $table->dropColumn([
                'verified_at',
                'verified_by',
                'rejection_reason',
            ]);
        });
    }
};