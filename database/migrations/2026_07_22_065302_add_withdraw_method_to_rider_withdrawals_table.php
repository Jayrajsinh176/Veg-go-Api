<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_withdrawals', function (Blueprint $table) {

            $table->enum('withdraw_method', [
                'bank',
                'upi'
            ])->after('amount');

            $table->foreignId('bank_detail_id')
                ->nullable()
                ->after('withdraw_method')
                ->constrained('rider_bank_details')
                ->nullOnDelete();

            $table->foreignId('upi_detail_id')
                ->nullable()
                ->after('bank_detail_id')
                ->constrained('rider_upi_details')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rider_withdrawals', function (Blueprint $table) {

            $table->dropForeign(['bank_detail_id']);
            $table->dropForeign(['upi_detail_id']);

            $table->dropColumn([
                'withdraw_method',
                'bank_detail_id',
                'upi_detail_id',
            ]);
        });
    }
};