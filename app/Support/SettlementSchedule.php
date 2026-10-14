<?php

namespace App\Support;

use Carbon\Carbon;

class SettlementSchedule
{
    /**
     * Latest payout_date that is due right now: today once the payout time
     * has passed, otherwise yesterday.
     */
    public static function dueDate(): Carbon
    {
        return now()->format('H:i') >= config('settlement.payout_time', '09:00')
            ? today()
            : today()->subDay();
    }

    public static function isDue(Carbon $payoutDate): bool
    {
        return $payoutDate->lte(self::dueDate());
    }

    /**
     * When a payout_date is actually paid (date + payout time)
     */
    public static function payoutAt(Carbon $payoutDate): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', config('settlement.payout_time', '09:00')));

        return $payoutDate->copy()->setTime($hour, $minute);
    }
}
