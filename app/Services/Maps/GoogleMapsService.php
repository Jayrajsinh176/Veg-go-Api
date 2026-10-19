<?php

namespace App\Services\Maps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side Google Maps integration (Routes API).
 *
 * Every public method falls back to straight-line (haversine) calculation when
 * Google is disabled, not configured, or fails, so callers never break.
 */
class GoogleMapsService
{
    private const ROUTES_URL = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    // Average two-wheeler speed in town, used for ETA when Google is not available.
    private const FALLBACK_SPEED_KMPH = 20;

    public function isEnabled(): bool
    {
        return (bool) config('services.google_maps.enabled')
            && !empty(config('services.google_maps.api_key'));
    }

    /**
     * Road distance and travel time between two points.
     *
     * @return array{distance_km: float, duration_min: int, source: string}
     *               source is "google" or "haversine"
     */
    public function routeDistance(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        if ($this->isEnabled()) {
            $route = $this->fetchRoute($fromLat, $fromLng, $toLat, $toLng);

            if ($route !== null) {
                return $route;
            }
        }

        $distanceKm = self::haversine($fromLat, $fromLng, $toLat, $toLng);

        return [
            'distance_km' => $distanceKm,
            'duration_min' => (int) max(1, ceil($distanceKm / self::FALLBACK_SPEED_KMPH * 60)),
            'source' => 'haversine',
        ];
    }

    /**
     * Straight-line distance between two coordinates in kilometers.
     */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;

        $latDiff = deg2rad($lat2 - $lat1);
        $lngDiff = deg2rad($lng2 - $lng1);

        $a = sin($latDiff / 2) * sin($latDiff / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lngDiff / 2) * sin($lngDiff / 2);

        return round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    /**
     * Call Routes API (cached). Returns null on any failure so the caller can fall back.
     */
    private function fetchRoute(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        // ~11 m precision is enough for delivery distance and keeps the cache hit rate high.
        $cacheKey = sprintf(
            'gmaps:route:%.4f,%.4f:%.4f,%.4f',
            $fromLat, $fromLng, $toLat, $toLng
        );

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('services.google_maps.timeout', 5))
                ->withHeaders([
                    'X-Goog-Api-Key' => config('services.google_maps.api_key'),
                    'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration',
                ])
                ->post(self::ROUTES_URL, [
                    'origin' => $this->waypoint($fromLat, $fromLng),
                    'destination' => $this->waypoint($toLat, $toLng),
                    'travelMode' => 'TWO_WHEELER',
                    'routingPreference' => 'TRAFFIC_UNAWARE',
                    'units' => 'METRIC',
                ]);

            if (!$response->successful()) {
                Log::warning('Google Routes API request failed', [
                    'http_status' => $response->status(),
                    'error_status' => $response->json('error.status'),
                ]);

                return null;
            }

            $route = $response->json('routes.0');

            if (!is_array($route) || !isset($route['distanceMeters'])) {
                Log::info('Google Routes API returned no route', [
                    'from' => [round($fromLat, 4), round($fromLng, 4)],
                    'to' => [round($toLat, 4), round($toLng, 4)],
                ]);

                return null;
            }

            // Duration comes back as a string like "754s".
            $durationSeconds = (int) rtrim((string) ($route['duration'] ?? '0s'), 's');

            $result = [
                'distance_km' => round(((int) $route['distanceMeters']) / 1000, 2),
                'duration_min' => (int) max(1, ceil($durationSeconds / 60)),
                'source' => 'google',
            ];

            Cache::put($cacheKey, $result, (int) config('services.google_maps.cache_ttl', 21600));

            return $result;
        } catch (Throwable $e) {
            Log::warning('Google Routes API exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function waypoint(float $lat, float $lng): array
    {
        return [
            'location' => [
                'latLng' => [
                    'latitude' => $lat,
                    'longitude' => $lng,
                ],
            ],
        ];
    }
}
