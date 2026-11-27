<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GreenCare\Models\CrewRouteSchedule;
use Modules\GreenCare\Models\CrewRouteStop;

require_once __DIR__.'/GreenCareSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    gcSetUp($this);
});

/**
 * Three sites in the north of Milan (Monza, Sesto, Cinisello) and one in the south (Lodi).
 *
 * @return array<string, Modules\GreenCare\Models\SeasonalMaintenanceContract>
 */
function gcMilanContracts(object $test): array
{
    $contracts = [];

    foreach (['monza' => [45.5845, 9.2744], 'sesto' => [45.5336, 9.2336], 'cinisello' => [45.5578, 9.2146], 'lodi' => [45.3142, 9.5030]] as $name => [$lat, $lng]) {
        $contracts[$name] = gcContract($test, gcSite($test, ['name' => ucfirst($name), 'latitude' => $lat, 'longitude' => $lng, 'lawn_area_sqm' => 600, 'hedge_length_meters' => 0, 'tree_count' => 0]), ['contract_number' => 'GC-'.$name]);
    }

    return $contracts;
}

test('the week must start on a Monday and a crew must exist', function (): void {
    $this->postJson(GC_API.'/routes/optimize', ['week_start' => '2026-05-05'])->assertStatus(422)->assertJsonPath('code', 'no_crews_available');

    gcCrew($this);

    $this->postJson(GC_API.'/routes/optimize', ['week_start' => '2026-05-05'])->assertStatus(422)->assertJsonPath('code', 'week_start_invalid');
});

test('the due contracts become ordered stops of the crew within its capacity', function (): void {
    $crew = gcCrew($this, ['daily_capacity_minutes' => 480]);
    $contracts = gcMilanContracts($this);

    $response = $this->postJson(GC_API.'/routes/optimize', ['week_start' => '2026-05-04'])->assertOk()
        ->assertJsonPath('data.summary.stops_created', 4)
        ->assertJsonPath('data.unassigned', []);

    $routes = $response->json('data.routes');
    $stops = collect($routes)->flatMap(fn (array $route): array => $route['stops']);

    expect($stops->pluck('contract_id')->sort()->values()->all())->toBe(collect($contracts)->pluck('id')->sort()->values()->all())
        ->and($routes[0]['crew_team_id'])->toBe($crew->id)
        ->and($routes[0]['assigned_vehicle_plate'])->toBe('FG123AB')
        ->and($routes[0]['route_optimization_metadata']['total_km'])->toBeGreaterThan(0)
        ->and($routes[0]['stops'][0]['stop_order'])->toBe(1);

    // The north cluster is served nearest-neighbour from the depot: the Sesto site (closest to the Milan depot) comes before Monza.
    $order = collect($routes[0]['stops'])->pluck('contract_id')->all();
    expect(array_search($contracts['sesto']->id, $order, true))->toBeLessThan(array_search($contracts['monza']->id, $order, true));
});

test('a small crew capacity spreads the stops over several days', function (): void {
    gcCrew($this, ['daily_capacity_minutes' => 70]);
    gcMilanContracts($this);

    $routes = $this->postJson(GC_API.'/routes/optimize', ['week_start' => '2026-05-04'])->assertOk()->json('data.routes');

    expect(count($routes))->toBeGreaterThan(1)
        ->and(collect($routes)->pluck('schedule_date')->unique()->count())->toBe(count($routes));
});

test('the planner never plans a visit the equipment cannot serve', function (): void {
    gcCrew($this, ['equipment' => ['hedge_trimmers' => 1]]);
    gcMilanContracts($this);

    $this->postJson(GC_API.'/routes/optimize', ['week_start' => '2026-05-04'])->assertOk()
        ->assertJsonPath('data.summary.stops_created', 0)
        ->assertJsonPath('data.unassigned.0.reason', 'no_equipped_crew');
});

test('a site without coordinates is reported as unassigned', function (): void {
    gcCrew($this);
    gcContract($this, gcSite($this, ['latitude' => null, 'longitude' => null]));

    $this->postJson(GC_API.'/routes/optimize', ['week_start' => '2026-05-04'])->assertOk()
        ->assertJsonPath('data.unassigned.0.reason', 'no_coordinates');
});

test('contracts that are not active or out of season are not planned', function (): void {
    gcCrew($this);
    gcContract($this, gcSite($this), ['status' => 'suspended']);
    gcContract($this, gcSite($this), ['season_start_date' => '2026-10-01', 'season_end_date' => '2027-03-31']);

    $this->postJson(GC_API.'/routes/optimize', ['week_start' => '2026-05-04'])->assertOk()->assertJsonPath('data.summary.stops_created', 0);
});

test('a contract that is on schedule is not due, one that is behind is', function (): void {
    gcCrew($this);
    $ahead = gcContract($this, gcSite($this), ['included_passages_count' => 16, 'completed_passages_count' => 16]);
    $behind = gcContract($this, gcSite($this), ['included_passages_count' => 16, 'completed_passages_count' => 1]);

    $stops = collect($this->postJson(GC_API.'/routes/optimize', ['week_start' => '2026-05-04'])->assertOk()->json('data.routes'))->flatMap(fn (array $route): array => $route['stops']);

    expect($stops->pluck('contract_id')->all())->toBe([$behind->id])->not->toContain($ahead->id);
});

test('running the planner twice for the same week adds nothing', function (): void {
    gcCrew($this);
    gcMilanContracts($this);

    $this->postJson(GC_API.'/routes/optimize', ['week_start' => '2026-05-04'])->assertOk()->assertJsonPath('data.summary.stops_created', 4);
    $this->postJson(GC_API.'/routes/optimize', ['week_start' => '2026-05-04'])->assertOk()->assertJsonPath('data.summary.stops_created', 0);

    expect(CrewRouteStop::query()->count())->toBe(4);
});

test('a rainy Monday is used last and the route carries the alert', function (): void {
    gcFakeWeather(['2026-05-04' => ['precipitation_mm' => 14]]);
    gcCrew($this);
    gcContract($this, gcSite($this));

    $route = $this->postJson(GC_API.'/routes/optimize', ['week_start' => '2026-05-04'])->assertOk()->json('data.routes.0');

    expect($route['schedule_date'])->toBe('2026-05-05')
        ->and($route['weather_alert_status'])->toBe('clear')
        ->and($route['weather_forecast_summary']['source'])->toBe('fake');
});

test('the crews are chosen by the request and only active crews take part', function (): void {
    $active = gcCrew($this);
    gcCrew($this, ['is_active' => false]);
    $other = gcCrew($this);
    gcContract($this, gcSite($this));

    $routes = $this->postJson(GC_API.'/routes/optimize', ['week_start' => '2026-05-04', 'crew_team_ids' => [$other->id]])->assertOk()->json('data.routes');

    expect($routes[0]['crew_team_id'])->toBe($other->id)->not->toBe($active->id);
});

test('a route can be planned by hand, reordered and deleted until it starts', function (): void {
    $crew = gcCrew($this);
    $siteA = gcSite($this);
    $siteB = gcSite($this);

    $route = $this->postJson(GC_API.'/routes', [
        'schedule_date' => '2026-05-06',
        'crew_team_id' => $crew->id,
        'stops' => [['site_id' => $siteA->id], ['site_id' => $siteB->id, 'planned_activities' => ['hedging'], 'estimated_duration_minutes' => 90]],
    ])->assertCreated()->assertJsonPath('data.assigned_vehicle_plate', 'FG123AB')->json('data');

    [$first, $second] = [$route['stops'][0]['id'], $route['stops'][1]['id']];

    $this->putJson(GC_API.'/routes/'.$route['id'], ['stop_order' => [$second, $first]])->assertOk()
        ->assertJsonPath('data.stops.0.id', $second)
        ->assertJsonPath('data.stops.0.planned_activities.0', 'hedging');

    $this->deleteJson(GC_API.'/routes/'.$route['id'])->assertOk();
    expect(CrewRouteSchedule::query()->count())->toBe(0);
});

test('a route that has started cannot be reordered or deleted', function (): void {
    $crew = gcCrew($this);
    $site = gcSite($this);
    [$schedule, $stop] = gcStop($this, $crew, $site, gcContract($this, $site));
    gcStop($this, $crew, $site, null);
    gcCloseStop($this, $stop)->assertOk();

    $this->deleteJson(GC_API.'/routes/'.$schedule->id)->assertStatus(422)->assertJsonPath('code', 'route_locked');
    $this->putJson(GC_API.'/routes/'.$schedule->id, ['stop_order' => [$stop->id]])->assertStatus(422)->assertJsonPath('code', 'route_locked');
});
