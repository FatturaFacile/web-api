<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use Modules\GreenCare\Domain\Enums\EquipmentType;
use Modules\GreenCare\Domain\Enums\ServiceType;
use Modules\GreenCare\Domain\Support\GeoMath;

/**
 * Builds the routes of the crews: the due visits are clustered by geographic proximity (Haversine), a crew takes the cluster nearest to its
 * depot until its daily capacity (service time, set-up and travel) is full, and the stops of a route are ordered by nearest neighbour.
 * Pure computation on arrays: the handler loads the contracts and stores the result.
 *
 * @phpstan-type Candidate array{key: string, lat: float|null, lng: float|null, minutes: int, activities: list<string>}
 * @phpstan-type CrewInput array{id: string, depot: array{0: float, 1: float}|null, capacity: int, equipment: array<string, int>, used: array<string, int>}
 * @phpstan-type PlannedRoute array{day: string, crew_id: string, keys: list<string>, total_km: float, estimated_duration_minutes: int}
 */
final class RoutePlanner
{
    /**
     * Equipment of which a crew needs at least one for an activity.
     *
     * @var array<string, list<string>>
     */
    private const array REQUIRED_EQUIPMENT = [
        'mowing' => ['lawnmowers', 'ride_on_mowers'],
        'hedging' => ['hedge_trimmers'],
        'pruning' => ['chainsaws', 'hedge_trimmers'],
        'phytosanitary' => ['sprayers'],
        'snow_clearing' => ['snow_plows'],
    ];

    /**
     * @param  list<Candidate>  $candidates
     * @param  list<CrewInput>  $crews  `used` is the minutes already planned per day
     * @param  list<string>  $days  working days (Y-m-d) in order of preference
     * @return array{routes: list<PlannedRoute>, unassigned: list<array{key: string, reason: string}>}
     */
    public function plan(array $candidates, array $crews, array $days): array
    {
        $unassigned = [];
        $remaining = [];

        foreach ($candidates as $candidate) {
            if ($candidate['lat'] === null || $candidate['lng'] === null) {
                $unassigned[] = ['key' => $candidate['key'], 'reason' => 'no_coordinates'];

                continue;
            }

            if (! $this->anyCrewEquipped($candidate, $crews)) {
                $unassigned[] = ['key' => $candidate['key'], 'reason' => 'no_equipped_crew'];

                continue;
            }

            $remaining[$candidate['key']] = $candidate;
        }

        $routes = [];

        foreach ($days as $day) {
            foreach ($crews as $crew) {
                if ($remaining === []) {
                    break 2;
                }

                $route = $this->fillRoute($crew, $day, $remaining);

                if ($route === null) {
                    continue;
                }

                foreach ($route['keys'] as $key) {
                    unset($remaining[$key]);
                }

                $routes[] = $route;
            }
        }

        foreach ($remaining as $candidate) {
            $unassigned[] = ['key' => $candidate['key'], 'reason' => 'no_capacity'];
        }

        return ['routes' => $routes, 'unassigned' => $unassigned];
    }

    /**
     * @param  Candidate  $candidate
     * @param  CrewInput  $crew
     */
    public function crewCanServe(array $candidate, array $crew): bool
    {
        foreach ($candidate['activities'] as $activity) {
            $needs = self::REQUIRED_EQUIPMENT[$activity] ?? null;

            if ($needs === null) {
                continue;
            }

            $has = false;

            foreach ($needs as $equipment) {
                if (((int) ($crew['equipment'][$equipment] ?? 0)) > 0) {
                    $has = true;

                    break;
                }
            }

            if (! $has) {
                return false;
            }
        }

        return true;
    }

    /**
     * Travel time in minutes for a distance (straight line, road factor, average speed).
     */
    public function travelMinutes(float $km): int
    {
        return (int) ceil($km * (float) config('green_care.routing.road_factor', 1.3) / (float) config('green_care.routing.average_speed_kmh', 35) * 60);
    }

    /**
     * @param  Candidate  $candidate
     * @param  list<CrewInput>  $crews
     */
    private function anyCrewEquipped(array $candidate, array $crews): bool
    {
        foreach ($crews as $crew) {
            if ($this->crewCanServe($candidate, $crew)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  CrewInput  $crew
     * @param  array<string, Candidate>  $remaining
     * @return PlannedRoute|null
     */
    private function fillRoute(array $crew, string $day, array $remaining): ?array
    {
        $eligible = array_filter($remaining, fn (array $candidate): bool => $this->crewCanServe($candidate, $crew));

        if ($eligible === []) {
            return null;
        }

        $setup = (int) config('green_care.routing.setup_minutes_per_stop', 15);
        $capacity = $crew['capacity'] - ($crew['used'][$day] ?? 0);
        $depot = $crew['depot'];

        if ($capacity <= 0) {
            return null;
        }

        // The cluster starts at the stop farthest from the depot and grows with the nearest neighbours: the crew drives out once.
        $seed = $this->farthestFrom($eligible, $depot);
        $picked = [$seed['key'] => $seed];
        $used = $seed['minutes'] + $setup;
        $last = $seed;

        while (true) {
            $next = null;
            $nextDistance = INF;

            foreach ($eligible as $candidate) {
                if (isset($picked[$candidate['key']])) {
                    continue;
                }

                $distance = GeoMath::haversineKm((float) $last['lat'], (float) $last['lng'], (float) $candidate['lat'], (float) $candidate['lng']);
                $cost = $candidate['minutes'] + $setup + $this->travelMinutes($distance);

                if ($used + $cost > $capacity || $distance >= $nextDistance) {
                    continue;
                }

                $next = $candidate;
                $nextDistance = $distance;
            }

            if ($next === null) {
                break;
            }

            $picked[$next['key']] = $next;
            $used += $next['minutes'] + $setup + $this->travelMinutes($nextDistance);
            $last = $next;
        }

        $ordered = $this->nearestNeighbourOrder(array_values($picked), $depot);
        $km = $this->roundTripKm($ordered, $depot);
        $service = array_sum(array_map(static fn (array $stop): int => $stop['minutes'] + $setup, $ordered));

        return [
            'day' => $day,
            'crew_id' => $crew['id'],
            'keys' => array_map(static fn (array $stop): string => $stop['key'], $ordered),
            'total_km' => round($km, 1),
            'estimated_duration_minutes' => $service + $this->travelMinutes($km / (float) config('green_care.routing.road_factor', 1.3)),
        ];
    }

    /**
     * @param  array<string, Candidate>  $candidates
     * @param  array{0: float, 1: float}|null  $depot
     * @return Candidate
     */
    private function farthestFrom(array $candidates, ?array $depot): array
    {
        $best = reset($candidates);
        $bestDistance = -1.0;

        foreach ($candidates as $candidate) {
            $distance = $depot === null ? 0.0 : GeoMath::haversineKm($depot[0], $depot[1], (float) $candidate['lat'], (float) $candidate['lng']);

            if ($distance > $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @param  list<Candidate>  $stops
     * @param  array{0: float, 1: float}|null  $depot
     * @return list<Candidate>
     */
    private function nearestNeighbourOrder(array $stops, ?array $depot): array
    {
        if ($stops === []) {
            return [];
        }

        $ordered = [];
        $current = $depot ?? [(float) $stops[0]['lat'], (float) $stops[0]['lng']];

        while ($stops !== []) {
            $bestIndex = 0;
            $bestDistance = INF;

            foreach ($stops as $index => $stop) {
                $distance = GeoMath::haversineKm($current[0], $current[1], (float) $stop['lat'], (float) $stop['lng']);

                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestIndex = $index;
                }
            }

            $ordered[] = $stops[$bestIndex];
            $current = [(float) $stops[$bestIndex]['lat'], (float) $stops[$bestIndex]['lng']];
            array_splice($stops, $bestIndex, 1);
        }

        return $ordered;
    }

    /**
     * Road kilometres of depot -> stops -> depot.
     *
     * @param  list<Candidate>  $ordered
     * @param  array{0: float, 1: float}|null  $depot
     */
    private function roundTripKm(array $ordered, ?array $depot): float
    {
        $points = array_map(static fn (array $stop): array => [(float) $stop['lat'], (float) $stop['lng']], $ordered);

        if ($depot !== null) {
            array_unshift($points, $depot);
            $points[] = $depot;
        }

        $km = 0.0;

        for ($i = 1; $i < count($points); $i++) {
            $km += GeoMath::haversineKm($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
        }

        return $km * (float) config('green_care.routing.road_factor', 1.3);
    }

    /**
     * The activities of a visit of a contract: the mowing when the contract includes it, else the first service that is not seasonal.
     *
     * @param  list<string>  $includedServices
     * @return list<string>
     */
    public static function activitiesFor(array $includedServices): array
    {
        if ($includedServices === [] || in_array(ServiceType::Mowing->value, $includedServices, true)) {
            return [ServiceType::Mowing->value];
        }

        $seasonal = [ServiceType::SnowClearing->value, ServiceType::IrrigationWinterization->value, ServiceType::IrrigationStartup->value];
        $regular = array_values(array_diff($includedServices, $seasonal));

        return [$regular[0] ?? $includedServices[0]];
    }

    /**
     * Equipment of a crew as an array keyed by every equipment type.
     *
     * @param  array<string, mixed>|null  $equipment
     * @return array<string, int>
     */
    public static function normalizeEquipment(?array $equipment): array
    {
        $normalized = [];

        foreach (EquipmentType::cases() as $type) {
            $normalized[$type->value] = max(0, (int) ($equipment[$type->value] ?? 0));
        }

        return $normalized;
    }
}
