<?php

namespace App\Console\Commands;

use App\Services\Restaurant\RestaurantOfferService;
use Illuminate\Console\Command;

class SyncRestaurantOffers extends Command
{
    protected $signature = 'offers:sync';

    protected $description = 'Apply offers that have started and restore prices for offers that have ended';

    public function handle(RestaurantOfferService $offerService): int
    {
        $count = $offerService->syncAll();

        $this->info("Synced {$count} menu item(s).");

        return self::SUCCESS;
    }
}
