<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_weekly_settlements', function (Blueprint $table) {
            // Wallet balance carried into the cycle
            $table->decimal('opening_balance', 12, 2)->nullable()->after('total_amount');

            // Wallet credits / debits made during the cycle
            $table->decimal('total_credit', 12, 2)->nullable()->after('opening_balance');
            $table->decimal('total_debit', 12, 2)->nullable()->after('total_credit');

            // Wallet balance at the Wednesday cut-off (what the payout is based on)
            $table->decimal('closing_balance', 12, 2)->nullable()->after('total_debit');

            // Pending COD recovered from the wallet at payout
            $table->decimal('cod_deducted', 12, 2)->default(0)->after('closing_balance');

            // Amount actually paid out to the rider
            $table->decimal('payout_amount', 12, 2)->default(0)->after('cod_deducted');
        });

        // Settlements paid before this change paid out their total_amount
        DB::table('rider_weekly_settlements')
            ->where('status', 'successful')
            ->update(['payout_amount' => DB::raw('total_amount')]);
    }

    public function down(): void
    {
        Schema::table('rider_weekly_settlements', function (Blueprint $table) {
            $table->dropColumn([
                'opening_balance',
                'total_credit',
                'total_debit',
                'closing_balance',
                'cod_deducted',
                'payout_amount',
            ]);
        });
    }
};
