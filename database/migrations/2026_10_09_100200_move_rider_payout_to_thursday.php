<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rider cycles stay Wednesday -> Wednesday; the payout moves to the
     * Thursday morning after the cut-off. Unpaid rows created with the old
     * Wednesday payout date are moved one day.
     */
    public function up(): void
    {
        DB::table('rider_weekly_settlements')
            ->where('status', '!=', 'successful')
            ->update(['payout_date' => DB::raw('DATE_ADD(week_start, INTERVAL 8 DAY)')]);
    }

    public function down(): void
    {
        DB::table('rider_weekly_settlements')
            ->where('status', '!=', 'successful')
            ->update(['payout_date' => DB::raw('DATE_ADD(week_start, INTERVAL 7 DAY)')]);
    }
};
