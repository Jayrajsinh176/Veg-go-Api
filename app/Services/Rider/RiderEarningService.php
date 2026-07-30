<?php

namespace App\Services\Rider;

use App\Models\RiderEarningSlab;
use App\Models\RiderEarningSetting;
use Carbon\Carbon;

class RiderEarningService
{
    public function getBaseEarning(float $distance): ?RiderEarningSlab
    {
        return RiderEarningSlab::where('status', true)
            ->where('min_distance', '<=', $distance)
            ->where('max_distance', '>=', $distance)
            ->first();
    }

    public function isNightDelivery(): bool
    {
        $nightStart = RiderEarningSetting::where('setting_key', 'night_start')->value('setting_value');
        $nightEnd = RiderEarningSetting::where('setting_key', 'night_end')->value('setting_value');

        $now = Carbon::now()->format('H:i:s');

        // Handles time range crossing midnight (18:00 -> 02:00)
        if ($nightStart > $nightEnd) {
            return $now >= $nightStart || $now <= $nightEnd;
        }

        return $now >= $nightStart && $now <= $nightEnd;
    }

    public function getNightBonus(): float
{
    return (float) RiderEarningSetting::where('setting_key', 'night_bonus')
        ->value('setting_value');
}

public function calculateOrderEarning(float $distance): array
{
    $slab = $this->getBaseEarning($distance);

    if (!$slab) {
        return [
            'base_amount' => 0,
            'night_bonus' => 0,
            'total_amount' => 0,
        ];
    }

    $baseAmount = (float) $slab->base_amount;
    $nightBonus = $this->isNightDelivery() ? $this->getNightBonus() : 0;
    $totalAmount = $baseAmount + $nightBonus;

    return [
        'base_amount' => $baseAmount,
        'night_bonus' => $nightBonus,
        'total_amount' => $totalAmount,
    ];
}

}