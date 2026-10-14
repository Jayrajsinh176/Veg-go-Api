<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// Weekly payouts: each command only pays cycles whose payout morning
// (config/settlement.php payout_time) has arrived
Schedule::command('rider:process-auto-payouts')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('restaurant:process-auto-payouts')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('offers:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Customer APIs already hide ads past end_at; this keeps the stored status in step
Schedule::command('advertisements:expire')
    ->everyMinute()
    ->withoutOverlapping();

// Confirms PayU order payments whose browser callback never arrived (app closed mid-payment)
Schedule::command('payu:reconcile-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// P0 Fix: Auto-cancel online-payment orders that remain unpaid after 5 minutes
Schedule::command('orders:expire-unpaid')
    ->everyMinute()
    ->withoutOverlapping();
