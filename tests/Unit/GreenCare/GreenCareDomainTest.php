<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\GreenCare\Application\Services\DueInterventionPlanner;
use Modules\GreenCare\Application\Services\RoutePlanner;
use Modules\GreenCare\Domain\Compliance\ChJardinSuisseUfamStrategy;
use Modules\GreenCare\Domain\Compliance\ComplianceStrategyResolver;
use Modules\GreenCare\Domain\Compliance\ItQuadernoCampagnaCerStrategy;
use Modules\GreenCare\Domain\Enums\DosageUnit;
use Modules\GreenCare\Domain\Enums\LicenseType;
use Modules\GreenCare\Domain\Enums\WeatherAlertStatus;
use Modules\GreenCare\Domain\Support\DoseCalculator;
use Modules\GreenCare\Domain\Support\GeoMath;
use Modules\GreenCare\Domain\Weather\WeatherAlertEvaluator;
use Modules\GreenCare\Domain\Weather\WeatherForecast;
use Modules\GreenCare\Models\OperatorPhytosanitaryLicense;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;

function gcForecast(float $rain = 0, float $min = 10, float $wind = 5): WeatherForecast
{
    return new WeatherForecast($rain, $min, $min + 10, $wind, 'test', '2026-05-04T00:00:00+00:00');
}

test('the dose becomes the quantity of product in litres or kilograms', function (): void {
    // 2 l/ha on 5000 m2.
    expect(DoseCalculator::productQuantity(DosageUnit::LPerHa, 2, 5000, null))->toBe(1.0)
        // 150 ml per 100 litres of mixture, 200 litres sprayed.
        ->and(DoseCalculator::productQuantity(DosageUnit::MlPerHl, 150, 0, 200))->toBe(0.3)
        // 25 g per 100 m2 on 800 m2 = 200 g.
        ->and(DoseCalculator::productQuantity(DosageUnit::GPer100Sqm, 25, 800, null))->toBe(0.2)
        // Without the spray volume a ml/hl dose cannot be converted.
        ->and(DoseCalculator::productQuantity(DosageUnit::MlPerHl, 150, 500, null))->toBe(0.0)
        ->and(DoseCalculator::needsWaterVolume(DosageUnit::MlPerHl))->toBeTrue()
        ->and(DoseCalculator::needsWaterVolume(DosageUnit::LPerHa))->toBeFalse();
});

test('the Haversine distance matches the known distance between two cities', function (): void {
    // Milan - Como is about 40 km as the crow flies.
    $km = GeoMath::haversineKm(45.4642, 9.19, 45.8081, 9.0852);

    expect($km)->toBeGreaterThan(38)->toBeLessThan(42)
        ->and(GeoMath::haversineKm(45.0, 9.0, 45.0, 9.0))->toBe(0.0)
        ->and(GeoMath::centroid([[45.0, 9.0], [47.0, 11.0]]))->toBe([46.0, 10.0])
        ->and(GeoMath::centroid([]))->toBeNull();
});

test('the Swiss strategy validates UFAG registration numbers, OTR waste codes and has no condominium withholding', function (): void {
    $strategy = new ChJardinSuisseUfamStrategy;

    expect($strategy->jurisdiction())->toBe('CH')
        ->and($strategy->acceptedLicenseTypes())->toBe([LicenseType::Per, LicenseType::Pnrp, LicenseType::Opf])
        ->and($strategy->isValidRegistrationNumber('W-6004'))->toBeTrue()
        ->and($strategy->isValidRegistrationNumber('w-7235-1'))->toBeTrue()
        ->and($strategy->isValidRegistrationNumber('12345'))->toBeFalse()
        ->and($strategy->isValidRegistrationNumber('W-12'))->toBeFalse()
        ->and($strategy->defaultWasteCode())->toBe('OTR 20 02 01')
        ->and($strategy->isValidWasteCode('OTR 02 01 03'))->toBeTrue()
        ->and($strategy->isValidWasteCode('20 02 01'))->toBeTrue()
        ->and($strategy->isValidWasteCode('CER 20 02 01'))->toBeFalse()
        ->and($strategy->condominiumWithholding())->toBeNull()
        ->and($strategy->regiaHourlyRates()['gardener'])->toBe(68.0)
        ->and($strategy->botanicalCatalog())->not->toBeEmpty();
});

test('the Italian strategy validates the Ministero della Salute number, CER codes and applies 4 percent to condominiums', function (): void {
    $strategy = new ItQuadernoCampagnaCerStrategy;

    expect($strategy->jurisdiction())->toBe('IT')
        ->and($strategy->acceptedLicenseTypes())->toBe([LicenseType::Pan])
        ->and($strategy->isValidRegistrationNumber('12345'))->toBeTrue()
        ->and($strategy->isValidRegistrationNumber('10256/2'))->toBeTrue()
        ->and($strategy->isValidRegistrationNumber('W-6004'))->toBeFalse()
        ->and($strategy->defaultWasteCode())->toBe('CER 20 02 01')
        ->and($strategy->isValidWasteCode('CER 20 02 01'))->toBeTrue()
        ->and($strategy->isValidWasteCode('20 02 01'))->toBeTrue()
        ->and($strategy->isValidWasteCode('OTR 20 02 01'))->toBeFalse()
        ->and($strategy->condominiumWithholding())->toMatchArray(['percent' => 4.0, 'type' => 'RT02', 'legal_basis' => 'Art. 25-ter DPR 600/73']);
});

test('the resolver picks the strategy of the jurisdiction and sends other countries to the Italian rules', function (): void {
    $resolver = new ComplianceStrategyResolver;

    expect($resolver->for('CH'))->toBeInstanceOf(ChJardinSuisseUfamStrategy::class)
        ->and($resolver->for('ch'))->toBeInstanceOf(ChJardinSuisseUfamStrategy::class)
        ->and($resolver->for('IT'))->toBeInstanceOf(ItQuadernoCampagnaCerStrategy::class)
        ->and($resolver->normalize('DE'))->toBe('IT')
        ->and($resolver->normalize('CH'))->toBe('CH')
        ->and($resolver->normalize(null))->toBe('IT');
});

test('license types belong to one jurisdiction', function (): void {
    expect(LicenseType::Pan->jurisdiction())->toBe('IT')
        ->and(LicenseType::Per->jurisdiction())->toBe('CH')
        ->and(LicenseType::Pnrp->jurisdiction())->toBe('CH')
        ->and(LicenseType::Opf->jurisdiction())->toBe('CH');
});

test('a license is valid from the issue day to the end of the expiry day and only while active', function (): void {
    $license = new OperatorPhytosanitaryLicense(['issue_date' => '2025-01-10', 'expiry_date' => '2026-05-04', 'is_active' => true]);

    expect($license->isValidOn(Carbon::parse('2025-01-10 08:00')))->toBeTrue()
        ->and($license->isValidOn(Carbon::parse('2026-05-04 23:30')))->toBeTrue()
        ->and($license->isValidOn(Carbon::parse('2026-05-05 00:00')))->toBeFalse()
        ->and($license->isValidOn(Carbon::parse('2025-01-09 12:00')))->toBeFalse();

    $license->is_active = false;
    expect($license->isValidOn(Carbon::parse('2025-06-01')))->toBeFalse();
});

test('the weather alert follows rain, wind and frost, frost first', function (): void {
    $evaluator = new WeatherAlertEvaluator;

    expect($evaluator->evaluate(null))->toBe(WeatherAlertStatus::Clear)
        ->and($evaluator->evaluate(gcForecast()))->toBe(WeatherAlertStatus::Clear)
        ->and($evaluator->evaluate(gcForecast(rain: 4.9)))->toBe(WeatherAlertStatus::Clear)
        ->and($evaluator->evaluate(gcForecast(rain: 5)))->toBe(WeatherAlertStatus::RainWarning)
        ->and($evaluator->evaluate(gcForecast(wind: 55)))->toBe(WeatherAlertStatus::RainWarning)
        ->and($evaluator->evaluate(gcForecast(min: 0)))->toBe(WeatherAlertStatus::FrostAlert)
        ->and($evaluator->evaluate(gcForecast(rain: 20, min: -3)))->toBe(WeatherAlertStatus::FrostAlert);
});

test('rain stops mowing and spraying, not hedging; frost stops irrigation start-up too', function (): void {
    $evaluator = new WeatherAlertEvaluator;

    expect($evaluator->blocksActivities(WeatherAlertStatus::RainWarning, ['mowing']))->toBeTrue()
        ->and($evaluator->blocksActivities(WeatherAlertStatus::RainWarning, ['phytosanitary']))->toBeTrue()
        ->and($evaluator->blocksActivities(WeatherAlertStatus::RainWarning, ['hedging']))->toBeFalse()
        ->and($evaluator->blocksActivities(WeatherAlertStatus::FrostAlert, ['irrigation_startup']))->toBeTrue()
        ->and($evaluator->blocksActivities(WeatherAlertStatus::Clear, ['mowing']))->toBeFalse();
});

test('the forecast survives the cache round trip', function (): void {
    $forecast = gcForecast(rain: 3.5, min: 4, wind: 22);

    expect(WeatherForecast::fromArray($forecast->toArray()))->toEqual($forecast);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function gcUnsavedContract(array $attributes = []): SeasonalMaintenanceContract
{
    return new SeasonalMaintenanceContract(array_merge([
        'season_start_date' => '2026-04-01', 'season_end_date' => '2026-09-30', 'included_passages_count' => 16, 'completed_passages_count' => 0,
    ], $attributes));
}

test('a contract is due when the passages of the elapsed season are not done yet', function (): void {
    $planner = new DueInterventionPlanner;
    $week = Carbon::parse('2026-05-04');

    // Five weeks of 26 gone: ceil(16 * 34 / 183) = 3 passages expected by the end of the week.
    expect($planner->isDue(gcUnsavedContract(['completed_passages_count' => 0]), $week, 0))->toBeTrue()
        ->and($planner->isDue(gcUnsavedContract(['completed_passages_count' => 2]), $week, 0))->toBeTrue()
        ->and($planner->isDue(gcUnsavedContract(['completed_passages_count' => 3]), $week, 0))->toBeFalse()
        ->and($planner->isDue(gcUnsavedContract(['completed_passages_count' => 2]), $week, 1))->toBeFalse();
});

test('a contract is due only inside its season, and one without included passages every week', function (): void {
    $planner = new DueInterventionPlanner;

    expect($planner->isDue(gcUnsavedContract(), Carbon::parse('2026-03-23'), 0))->toBeFalse()
        ->and($planner->isDue(gcUnsavedContract(), Carbon::parse('2026-10-05'), 0))->toBeFalse()
        ->and($planner->isDue(gcUnsavedContract(['included_passages_count' => 0, 'completed_passages_count' => 40]), Carbon::parse('2026-07-06'), 0))->toBeTrue()
        ->and($planner->isDue(gcUnsavedContract(['included_passages_count' => 0]), Carbon::parse('2026-07-06'), 1))->toBeFalse();
});

test('the planner fills the crew capacity with the nearest sites and leaves the rest for the next day', function (): void {
    $planner = new RoutePlanner;
    $candidates = [
        ['key' => 'a', 'lat' => 45.50, 'lng' => 9.20, 'minutes' => 120, 'activities' => ['mowing']],
        ['key' => 'b', 'lat' => 45.51, 'lng' => 9.21, 'minutes' => 120, 'activities' => ['mowing']],
        ['key' => 'c', 'lat' => 45.52, 'lng' => 9.22, 'minutes' => 120, 'activities' => ['mowing']],
        ['key' => 'far', 'lat' => 46.00, 'lng' => 9.80, 'minutes' => 120, 'activities' => ['mowing']],
    ];
    $crew = ['id' => 'crew', 'depot' => [45.4642, 9.19], 'capacity' => 420, 'equipment' => RoutePlanner::normalizeEquipment(['lawnmowers' => 1]), 'used' => []];

    $plan = $planner->plan($candidates, [$crew], ['2026-05-04', '2026-05-05']);

    expect($plan['unassigned'])->toBe([])
        ->and($plan['routes'])->toHaveCount(2)
        ->and($plan['routes'][0]['day'])->toBe('2026-05-04')
        // The cluster starts at the stop farthest from the depot and takes the nearest neighbours while the capacity lasts.
        ->and($plan['routes'][0]['keys'])->toContain('far')
        ->and(array_merge($plan['routes'][0]['keys'], $plan['routes'][1]['keys']))->toHaveCount(4)
        ->and(collect($plan['routes'])->flatMap(fn (array $route): array => $route['keys'])->sort()->values()->all())->toBe(['a', 'b', 'c', 'far'])
        ->and($plan['routes'][0]['total_km'])->toBeGreaterThan(0.0);
});

test('the planner reports what it cannot serve and respects the minutes already planned', function (): void {
    $planner = new RoutePlanner;
    $crew = ['id' => 'crew', 'depot' => null, 'capacity' => 300, 'equipment' => RoutePlanner::normalizeEquipment(['lawnmowers' => 1]), 'used' => ['2026-05-04' => 300]];
    $candidates = [
        ['key' => 'nogps', 'lat' => null, 'lng' => null, 'minutes' => 60, 'activities' => ['mowing']],
        ['key' => 'spray', 'lat' => 45.5, 'lng' => 9.2, 'minutes' => 60, 'activities' => ['phytosanitary']],
        ['key' => 'full', 'lat' => 45.5, 'lng' => 9.2, 'minutes' => 60, 'activities' => ['mowing']],
    ];

    $plan = $planner->plan($candidates, [$crew], ['2026-05-04']);
    $reasons = collect($plan['unassigned'])->pluck('reason', 'key')->all();

    expect($plan['routes'])->toBe([])
        ->and($reasons)->toBe(['nogps' => 'no_coordinates', 'spray' => 'no_equipped_crew', 'full' => 'no_capacity']);
});

test('the activity of a visit is the mowing, or the first regular service', function (): void {
    expect(RoutePlanner::activitiesFor(['mowing', 'hedging']))->toBe(['mowing'])
        ->and(RoutePlanner::activitiesFor([]))->toBe(['mowing'])
        ->and(RoutePlanner::activitiesFor(['snow_clearing', 'hedging']))->toBe(['hedging'])
        ->and(RoutePlanner::activitiesFor(['snow_clearing']))->toBe(['snow_clearing']);
});

test('the contract counts its extra passages beyond the included ones', function (): void {
    expect(gcUnsavedContract(['completed_passages_count' => 16])->extraPassagesCount())->toBe(0)
        ->and(gcUnsavedContract(['completed_passages_count' => 17])->extraPassagesCount())->toBe(1)
        ->and(gcUnsavedContract(['completed_passages_count' => 20])->extraPassagesCount())->toBe(4);
});
