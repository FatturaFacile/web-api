<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\GreenCare\Events\RoutesRescheduledDueToWeather;
use Modules\GreenCare\Models\CrewRouteSchedule;
use Modules\GreenCare\Models\CrewRouteStop;

require_once __DIR__.'/GreenCareSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    gcSetUp($this);
    $this->site = gcSite($this);
    $this->crew = gcCrew($this);
    $this->contract = gcContract($this, $this->site);
});

test('rain moves the mowing stops to the next clear day of the same crew and flags the routes', function (): void {
    Event::fake([RoutesRescheduledDueToWeather::class]);
    gcFakeWeather(['2026-05-04' => ['precipitation_mm' => 12]]);
    [$monday, $stop] = gcStop($this, $this->crew, $this->site, $this->contract, '2026-05-04');

    $this->postJson(GC_API.'/routes/auto-reschedule-weather', ['from' => '2026-05-04', 'to' => '2026-05-08'])->assertOk()
        ->assertJsonPath('data.rescheduled_stops', 1)
        ->assertJsonCount(1, 'data.affected_routes');

    $moved = CrewRouteStop::query()->findOrFail($stop->id);
    $target = CrewRouteSchedule::query()->findOrFail($moved->route_schedule_id);
    $source = CrewRouteSchedule::query()->findOrFail($monday->id);

    expect($target->schedule_date->toDateString())->toBe('2026-05-05')
        ->and($target->crew_team_id)->toBe($this->crew->id)
        ->and($target->weather_alert_status->value)->toBe('clear')
        ->and($source->status->value)->toBe('postponed')
        ->and($source->weather_alert_status->value)->toBe('rescheduled_due_to_weather');

    Event::assertDispatched(RoutesRescheduledDueToWeather::class, fn (RoutesRescheduledDueToWeather $event): bool => $event->movedStopIds === [$stop->id]);
});

test('frost is an alert too and skips the days that are not clear or not working days', function (): void {
    // Monday frost, Tuesday rain, Wednesday clear.
    gcFakeWeather(['2026-05-04' => ['temp_min' => -2], '2026-05-05' => ['precipitation_mm' => 20]]);
    [, $stop] = gcStop($this, $this->crew, $this->site, $this->contract, '2026-05-04');

    $this->postJson(GC_API.'/routes/auto-reschedule-weather', ['from' => '2026-05-04', 'to' => '2026-05-04'])->assertOk()->assertJsonPath('data.rescheduled_stops', 1);

    $target = CrewRouteSchedule::query()->findOrFail(CrewRouteStop::query()->findOrFail($stop->id)->route_schedule_id);
    expect($target->schedule_date->toDateString())->toBe('2026-05-06');
});

test('a Friday alert goes to Monday, not to the weekend', function (): void {
    gcFakeWeather(['2026-05-08' => ['precipitation_mm' => 9]]);
    [, $stop] = gcStop($this, $this->crew, $this->site, $this->contract, '2026-05-08');

    $this->postJson(GC_API.'/routes/auto-reschedule-weather', ['from' => '2026-05-08', 'to' => '2026-05-08'])->assertOk()->assertJsonPath('data.rescheduled_stops', 1);

    $target = CrewRouteSchedule::query()->findOrFail(CrewRouteStop::query()->findOrFail($stop->id)->route_schedule_id);
    expect($target->schedule_date->toDateString())->toBe('2026-05-11');
});

test('work that the weather does not stop stays on the day, with the alert on the route', function (): void {
    gcFakeWeather(['2026-05-04' => ['precipitation_mm' => 12]]);
    [$route, $stop] = gcStop($this, $this->crew, $this->site, $this->contract, '2026-05-04', ['planned_activities' => ['hedging']]);

    $this->postJson(GC_API.'/routes/auto-reschedule-weather', ['from' => '2026-05-04', 'to' => '2026-05-04'])->assertOk()->assertJsonPath('data.rescheduled_stops', 0);

    expect(CrewRouteStop::query()->findOrFail($stop->id)->route_schedule_id)->toBe($route->id)
        ->and(CrewRouteSchedule::query()->findOrFail($route->id)->weather_alert_status->value)->toBe('rain_warning');
});

test('a day with a clear forecast is left alone and routes that started are never touched', function (): void {
    gcFakeWeather(['2026-05-04' => ['precipitation_mm' => 30], '2026-05-05' => []]);
    [$started, $stop] = gcStop($this, $this->crew, $this->site, $this->contract, '2026-05-04');
    gcStop($this, $this->crew, $this->site, null, '2026-05-04');
    gcCloseStop($this, $stop)->assertOk();
    [$clear] = gcStop($this, $this->crew, $this->site, $this->contract, '2026-05-05');

    $this->postJson(GC_API.'/routes/auto-reschedule-weather', ['from' => '2026-05-04', 'to' => '2026-05-05'])->assertOk()->assertJsonPath('data.rescheduled_stops', 0);

    expect(CrewRouteSchedule::query()->findOrFail($started->id)->weather_alert_status->value)->toBe('clear')
        ->and(CrewRouteSchedule::query()->findOrFail($clear->id)->weather_alert_status->value)->toBe('clear');
});

test('the stops are not moved to a day where the crew has no capacity left', function (): void {
    gcFakeWeather(['2026-05-04' => ['precipitation_mm' => 12]]);
    $crew = gcCrew($this, ['daily_capacity_minutes' => 100]);
    [, $stop] = gcStop($this, $crew, $this->site, $this->contract, '2026-05-04', ['estimated_duration_minutes' => 90]);
    gcStop($this, $crew, gcSite($this), null, '2026-05-05', ['estimated_duration_minutes' => 60]);

    $this->postJson(GC_API.'/routes/auto-reschedule-weather', ['from' => '2026-05-04', 'to' => '2026-05-04'])->assertOk();

    $target = CrewRouteSchedule::query()->findOrFail(CrewRouteStop::query()->findOrFail($stop->id)->route_schedule_id);
    expect($target->schedule_date->toDateString())->toBe('2026-05-06');
});

test('the weather of one route can be refreshed on demand', function (): void {
    gcFakeWeather(['2026-05-04' => ['temp_min' => -1]]);
    [$route] = gcStop($this, $this->crew, $this->site, $this->contract, '2026-05-04');

    $this->postJson(GC_API.'/routes/'.$route->id.'/refresh-weather')->assertOk()
        ->assertJsonPath('data.weather_alert_status', 'frost_alert')
        ->assertJsonPath('data.weather_forecast_summary.temp_min', -1);
});
