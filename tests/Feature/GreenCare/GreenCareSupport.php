<?php

declare(strict_types=1);

use App\Models\Contact;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Modules\GreenCare\Domain\Enums\BillingStatus;
use Modules\GreenCare\Domain\Enums\ContractStatus;
use Modules\GreenCare\Domain\Enums\LicenseType;
use Modules\GreenCare\Domain\Weather\IWeatherForecastProvider;
use Modules\GreenCare\Domain\Weather\WeatherForecast;
use Modules\GreenCare\Models\CrewRouteSchedule;
use Modules\GreenCare\Models\CrewRouteStop;
use Modules\GreenCare\Models\CrewTeam;
use Modules\GreenCare\Models\CrewTeamMember;
use Modules\GreenCare\Models\GreenSpaceSite;
use Modules\GreenCare\Models\OperatorPhytosanitaryLicense;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;

require_once __DIR__.'/../Invoicing/InvoicingBillingSupport.php';

const GC_API = '/api/v1/green-care';

/**
 * An Italian company with a customer, a condominium administrator, a composting facility and an accountant signed in. The clock stands on
 * Monday 4 May 2026, in the middle of a lawn season (April - September).
 */
function gcSetUp(object $test): void
{
    $test->travelTo('2026-05-04 09:00:00');
    billingSetUp($test);
    $test->administrator = billingInTenant($test->tenant, fn (): Contact => Contact::create(['tenant_id' => $test->tenant->id, 'type' => 'client', 'company_name' => 'Studio Rossi Amministrazioni', 'country' => 'IT', 'vat_number' => '02345678901']));
    $test->composter = billingInTenant($test->tenant, fn (): Contact => Contact::create(['tenant_id' => $test->tenant->id, 'type' => 'supplier', 'company_name' => 'Compost Lombardia Srl', 'country' => 'IT', 'vat_number' => '03456789012']));
    gcFakeWeather();
}

/**
 * Forecast per day (Y-m-d => precipitation / temperatures); days not listed are clear.
 *
 * @param  array<string, array{precipitation_mm?: float, temp_min?: float, temp_max?: float, wind_kmh?: float}>  $byDate
 */
function gcFakeWeather(array $byDate = []): void
{
    app()->instance(IWeatherForecastProvider::class, new class($byDate) implements IWeatherForecastProvider
    {
        /**
         * @param  array<string, array<string, float>>  $byDate
         */
        public function __construct(private readonly array $byDate) {}

        public function forecast(float $latitude, float $longitude, CarbonInterface $date): ?WeatherForecast
        {
            $day = $this->byDate[$date->format('Y-m-d')] ?? [];

            return new WeatherForecast(
                (float) ($day['precipitation_mm'] ?? 0),
                (float) ($day['temp_min'] ?? 12),
                (float) ($day['temp_max'] ?? 22),
                (float) ($day['wind_kmh'] ?? 8),
                'fake',
                now()->toIso8601String(),
            );
        }
    });
}

/**
 * @param  array<string, mixed>  $overrides
 */
function gcSite(object $test, array $overrides = []): GreenSpaceSite
{
    return billingInTenant($test->tenant, fn (): GreenSpaceSite => GreenSpaceSite::query()->create(array_merge([
        'tenant_id' => $test->tenant->id,
        'customer_id' => $test->customer->id,
        'code' => 'S-'.strtoupper(Str::random(5)),
        'name' => 'Giardino '.Str::random(4),
        'jurisdiction' => 'IT',
        'address' => 'Via dei Tigli 3, Milano',
        'latitude' => 45.4642,
        'longitude' => 9.19,
        'lawn_area_sqm' => 1200,
        'hedge_length_meters' => 40,
        'tree_count' => 6,
        'access_gate_code' => '4711',
        'is_active' => true,
    ], $overrides)));
}

/**
 * An active contract of 16 included passages, 1200 euro in monthly installments over April - September 2026.
 *
 * @param  array<string, mixed>  $overrides
 */
function gcContract(object $test, GreenSpaceSite $site, array $overrides = []): SeasonalMaintenanceContract
{
    return billingInTenant($test->tenant, fn (): SeasonalMaintenanceContract => SeasonalMaintenanceContract::query()->create(array_merge([
        'tenant_id' => $test->tenant->id,
        'contract_number' => 'GC-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'site_id' => $site->id,
        'customer_id' => $site->customer_id,
        'season_start_date' => '2026-04-01',
        'season_end_date' => '2026-09-30',
        'jurisdiction' => $site->jurisdiction,
        'billing_mode' => 'fixed_installments',
        'included_passages_count' => 16,
        'completed_passages_count' => 0,
        'extra_passage_unit_price' => 85,
        'fixed_fee_amount' => 1200,
        'installment_frequency' => 'monthly',
        'vat_rate' => 22,
        'included_services' => ['mowing'],
        'auto_bill_extra_passages' => true,
        'status' => ContractStatus::Active->value,
    ], $overrides)));
}

/**
 * A crew with one operator and mowing equipment.
 *
 * @param  array<string, mixed>  $overrides
 */
function gcCrew(object $test, array $overrides = []): CrewTeam
{
    return billingInTenant($test->tenant, function () use ($test, $overrides): CrewTeam {
        $crew = CrewTeam::query()->create(array_merge([
            'tenant_id' => $test->tenant->id,
            'code' => 'C-'.strtoupper(Str::random(4)),
            'name' => 'Squadra '.Str::random(3),
            'default_vehicle_plate' => 'FG123AB',
            'default_trailer_plate' => 'XA45678',
            'equipment' => ['lawnmowers' => 2, 'ride_on_mowers' => 1, 'hedge_trimmers' => 2, 'sprayers' => 1, 'chainsaws' => 1],
            'daily_capacity_minutes' => 480,
            'depot_latitude' => 45.4642,
            'depot_longitude' => 9.19,
            'is_active' => true,
        ], $overrides));
        CrewTeamMember::query()->create(['tenant_id' => $test->tenant->id, 'crew_team_id' => $crew->id, 'name' => 'Marco Verdi', 'role' => 'gardener', 'hourly_rate' => 25]);

        return $crew->load('members');
    });
}

function gcOperator(CrewTeam $crew): CrewTeamMember
{
    return $crew->members->first();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function gcLicense(object $test, CrewTeamMember $member, array $overrides = []): OperatorPhytosanitaryLicense
{
    return billingInTenant($test->tenant, fn (): OperatorPhytosanitaryLicense => OperatorPhytosanitaryLicense::query()->create(array_merge([
        'tenant_id' => $test->tenant->id,
        'crew_team_member_id' => $member->id,
        'license_type' => LicenseType::Pan->value,
        'jurisdiction' => 'IT',
        'license_number' => 'PAN-MI-1234',
        'issuing_authority' => 'Regione Lombardia',
        'issue_date' => '2024-01-15',
        'expiry_date' => '2029-01-14',
        'is_active' => true,
    ], $overrides)));
}

/**
 * A planned route of the crew with one stop of the site (and contract).
 *
 * @param  array<string, mixed>  $stopOverrides
 * @return array{0: CrewRouteSchedule, 1: CrewRouteStop}
 */
function gcStop(object $test, CrewTeam $crew, GreenSpaceSite $site, ?SeasonalMaintenanceContract $contract, string $date = '2026-05-04', array $stopOverrides = []): array
{
    return billingInTenant($test->tenant, function () use ($test, $crew, $site, $contract, $date, $stopOverrides): array {
        $schedule = CrewRouteSchedule::query()->where('crew_team_id', $crew->id)->whereDate('schedule_date', $date)->first()
            ?? CrewRouteSchedule::query()->create([
                'tenant_id' => $test->tenant->id,
                'schedule_date' => $date,
                'crew_team_id' => $crew->id,
                'assigned_vehicle_plate' => $crew->default_vehicle_plate,
                'weather_alert_status' => 'clear',
                'status' => 'planned',
            ]);
        $stop = CrewRouteStop::query()->create(array_merge([
            'tenant_id' => $test->tenant->id,
            'route_schedule_id' => $schedule->id,
            'site_id' => $site->id,
            'contract_id' => $contract?->id,
            'stop_order' => (int) CrewRouteStop::query()->where('route_schedule_id', $schedule->id)->max('stop_order') + 1,
            'planned_activities' => ['mowing'],
            'estimated_duration_minutes' => 60,
            'counts_toward_contract_passage' => true,
            'billing_status' => BillingStatus::NotBilled->value,
        ], $stopOverrides));

        return [$schedule, $stop];
    });
}

/**
 * Closes a stop through the API like the crew does.
 *
 * @param  array<string, mixed>  $payload
 */
function gcCloseStop(object $test, CrewRouteStop $stop, array $payload = []): Illuminate\Testing\TestResponse
{
    return $test->postJson(GC_API.'/route-stops/'.$stop->id.'/intervention', array_merge([
        'checkin_at' => now()->toIso8601String(),
        'checkout_at' => now()->addHour()->toIso8601String(),
        'checkin_gps' => ['lat' => 45.4642, 'lng' => 9.19, 'accuracy' => 8],
        'checkout_gps' => ['lat' => 45.4642, 'lng' => 9.19],
        'crew_hours' => [],
    ], $payload));
}
