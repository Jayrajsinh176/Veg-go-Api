<?php

namespace App\Console\Commands;

use App\Services\Restaurant\RestaurantAdvertisementService;
use Illuminate\Console\Command;

class ExpireRestaurantAdvertisements extends Command
{
    protected $signature = 'advertisements:expire';

    protected $description = 'Mark restaurant advertisements past their end time as expired';

    public function handle(RestaurantAdvertisementService $service): int
    {
        $count = $service->expireDue();

        $this->info("Expired {$count} advertisement(s).");

        return self::SUCCESS;
    }
}
