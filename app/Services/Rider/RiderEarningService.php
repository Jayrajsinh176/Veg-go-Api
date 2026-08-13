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
            ->orderBy('min_distance', 'asc')
            ->first();
    }

    public function isNightDelivery(): bool
    {
        $nightStart = RiderEarningSetting::where(
            'setting_key',
            'night_start'
        )->value('setting_value');

        $nightEnd = RiderEarningSetting::where(
            'setting_key',
            'night_end'
        )->value('setting_value');

        $now = Carbon::now()->format('H:i:s');

        if (!$nightStart || !$nightEnd) {
            return false;
        }

        // Handles time range crossing midnight
        // Example: 18:00 -> 02:00
        if ($nightStart > $nightEnd) {
            return $now >= $nightStart || $now <= $nightEnd;
        }

        return $now >= $nightStart && $now <= $nightEnd;
    }

    public function getNightBonus(): float
    {
        return (float) RiderEarningSetting::where(
            'setting_key',
            'night_bonus'
        )->value('setting_value');
    }

    public function getReturnBonus(float $distance): float
    {
        // 10 km to 15 km = ₹10
        if ($distance >= 10 && $distance <= 15) {
            return (float) RiderEarningSetting::where(
                'setting_key',
                'return_bonus_10_15'
            )->value('setting_value');
        }

        // More than 15 km to 20 km = ₹15
        if ($distance > 15 && $distance <= 20) {
            return (float) RiderEarningSetting::where(
                'setting_key',
                'return_bonus_15_20'
            )->value('setting_value');
        }

        return 0;
    }

    public function calculateOrderEarning(float $distance): array
    {
        $slab = $this->getBaseEarning($distance);

        if (!$slab) {
            return [
                'distance' => $distance,
                'base_amount' => 0,
                'return_bonus' => 0,
                'night_bonus' => 0,
                'total_amount' => 0,
            ];
        }

        $baseAmount = (float) $slab->base_amount;

        $returnBonus = $this->getReturnBonus($distance);

        $nightBonus = $this->isNightDelivery()
            ? $this->getNightBonus()
            : 0;

        $totalAmount = $baseAmount
            + $returnBonus
            + $nightBonus;

        return [
            'distance' => $distance,
            'base_amount' => $baseAmount,
            'return_bonus' => $returnBonus,
            'night_bonus' => $nightBonus,
            'total_amount' => $totalAmount,
        ];
    }
}