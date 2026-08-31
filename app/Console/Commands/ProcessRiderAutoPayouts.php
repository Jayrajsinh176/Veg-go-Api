<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Rider\RiderAutoPayoutService;

class ProcessRiderAutoPayouts extends Command
{
    protected $signature = 'rider:process-auto-payouts';

    protected $description = 'Process due rider automatic payouts';

    public function handle(RiderAutoPayoutService $payoutService): int
    {
        $count = $payoutService->processDueSettlements();

        $this->info(
            "Processed {$count} rider settlement(s)."
        );

        return self::SUCCESS;
    }
}