<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Models\WorkSite;

final class GeofenceService
{
    /**
     * Checks a punch position against the tenant's active work sites.
     *
     * Rules (backward compatible): with no active work sites nothing is enforced;
     * coordinates are mandatory only for mobile requests (`$strict`).
     *
     * @return array{allowed: bool, reason: ?string, site: ?WorkSite, distance_m: ?float}
     */
    public function check(?float $lat, ?float $lng, bool $strict): array
    {
        $sites = WorkSite::where('is_active', true)->get();

        if ($sites->isEmpty()) {
            return ['allowed' => true, 'reason' => null, 'site' => null, 'distance_m' => null];
        }

        if ($lat === null || $lng === null) {
            return $strict
                ? ['allowed' => false, 'reason' => 'position_required', 'site' => null, 'distance_m' => null]
                : ['allowed' => true, 'reason' => null, 'site' => null, 'distance_m' => null];
        }

        $nearest = $sites->sortBy(fn (WorkSite $s) => $s->distanceTo($lat, $lng))->first();
        $distance = $nearest->distanceTo($lat, $lng);

        foreach ($sites as $site) {
            if ($site->contains($lat, $lng)) {
                return ['allowed' => true, 'reason' => null, 'site' => $site, 'distance_m' => round($site->distanceTo($lat, $lng), 1)];
            }
        }

        return ['allowed' => false, 'reason' => 'outside_geofence', 'site' => $nearest, 'distance_m' => round($distance, 1)];
    }
}
