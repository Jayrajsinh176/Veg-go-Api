<?php

namespace App\Console\Commands;

use App\Services\Restaurant\RestaurantAutoPayoutService;
use Illuminate\Console\Command;

class ProcessRestaurantAutoPayouts extends Command
{
    protected $signature = 'restaurant:process-auto-payouts';

    protected $description = 'Process due restaurant weekly payouts';

    public function handle(RestaurantAutoPayoutService $payoutService): int
    {
        $count = $payoutService->processDueSettlements();

        $this->info(
            "Processed {$count} restaurant settlement(s)."
        );

        return self::SUCCESS;
    }
}
