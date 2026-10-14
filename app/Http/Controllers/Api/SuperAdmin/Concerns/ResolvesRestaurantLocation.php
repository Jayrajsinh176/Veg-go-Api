<?php

namespace App\Http\Controllers\Api\SuperAdmin\Concerns;

use App\Models\AdminActivityLog;
use App\Models\City;
use App\Models\CityArea;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Shared by the super admin Restaurants and Restaurant Applications pages.
 * Queries using these helpers join restaurant_addresses as "ra".
 */
trait ResolvesRestaurantLocation
{
    /**
     * City of the restaurant address: a city named in the address text,
     * otherwise the nearest city to the address pin (within ~50 km)
     */
    protected function citySql(): string
    {
        // ~50 km in squared degrees
        $maxDistance = 0.2;

        $distance = 'POW(c.latitude - ra.latitude, 2) + POW((c.longitude - ra.longitude) * COS(RADIANS(ra.latitude)), 2)';
        $nameMatch = "CONCAT_WS(' ', ra.full_address, ra.landmark, ra.complete_address) LIKE CONCAT('%', c.name, '%')";

        return "(SELECT c.id FROM cities c
            WHERE ra.id IS NOT NULL AND (({$nameMatch}) OR (ra.latitude IS NOT NULL AND ({$distance}) <= {$maxDistance}))
            ORDER BY ({$nameMatch}) DESC, COALESCE({$distance}, 999999) ASC
            LIMIT 1)";
    }

    protected function locationLookups(): array
    {
        return [
            'cities' => City::query()->orderBy('name')->get(['id', 'name']),
            'areas' => CityArea::query()->where('is_active', true)->orderBy('name')->get(['id', 'city_id', 'name']),
        ];
    }

    /**
     * Locality: a city area named in the address (areas of the restaurant's
     * city first), otherwise the landmark
     */
    protected function area(Restaurant $restaurant, ?int $cityId, Collection $areas): ?string
    {
        $text = strtolower(implode(' ', array_filter([
            $restaurant->full_address,
            $restaurant->landmark,
            $restaurant->complete_address,
        ])));

        if ($text !== '') {
            $match = $areas
                ->sortByDesc(fn ($area) => $area->city_id === $cityId)
                ->first(fn ($area) => str_contains($text, strtolower($area->name)));

            if ($match) {
                return $match->name;
            }
        }

        return $restaurant->landmark;
    }

    protected function fullAddress(Restaurant $restaurant): ?string
    {
        $parts = array_filter([
            $restaurant->shop_plot_number,
            $restaurant->floor ? 'Floor ' . $restaurant->floor : null,
            $restaurant->full_address,
            $restaurant->landmark,
            $restaurant->complete_address,
        ]);

        $address = implode(', ', array_unique($parts));

        if ($restaurant->pincode) {
            $address = trim($address . ' - ' . $restaurant->pincode, ' -');
        }

        return $address !== '' ? $address : null;
    }

    protected function log(Request $request, Restaurant $restaurant, string $action, string $summary, string $risk = 'Low'): void
    {
        // Logging must never break the actual change
        try {
            $admin = $request->user();

            AdminActivityLog::create([
                'admin_id' => $admin?->id,
                'admin_name' => $admin?->name,
                'admin_email' => $admin?->email,
                'action' => $action,
                'module' => 'Restaurants',
                'target' => '#RES-' . $restaurant->id,
                'change_summary' => $summary,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'source' => 'Super Admin Panel',
                'method' => $request->method(),
                'path' => $request->path(),
                'risk_level' => $risk,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
