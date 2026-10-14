<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Weekly Settlement Payout Time
    |--------------------------------------------------------------------------
    |
    | Cycles close at 00:00 on their cut-off day and are paid out the next
    | morning at this time (24h, app timezone).
    |
    | Restaurants: Monday 00:00 -> next Monday 00:00, paid Tuesday morning
    | Riders:      Wednesday 00:00 -> next Wednesday 00:00, paid Thursday morning
    |
    */

    'payout_time' => env('SETTLEMENT_PAYOUT_TIME', '09:00'),

];
