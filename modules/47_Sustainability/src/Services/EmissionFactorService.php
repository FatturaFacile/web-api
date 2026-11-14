<?php

declare(strict_types=1);

namespace Modules\Sustainability\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Sustainability\Models\EsgActivityRecord;
use Modules\Sustainability\Models\EsgEmissionFactor;
use Modules\Sustainability\Support\ActivityCatalog;
use Modules\Sustainability\Support\Decimal;

/**
 * The emission factor library. The scope and the reporting category come from the activity type. A factor that activity
 * records already use is immutable in value, unit and type: a new version is created instead (same code, version + 1,
 * the previous one is deactivated), so every record keeps the exact factor it was computed with.
 */
final readonly class EmissionFactorService
{
    private const string ExampleSource = 'EXAMPLE value (public-style, indicative): replace with the official source of the company';

    public function __construct(private SustainabilityLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): EsgEmissionFactor
    {
        return DB::transaction(function () use ($tenantId, $data): EsgEmissionFactor {
            TransactionLock::acquire("esg_factors:{$tenantId}:{$data['code']}");
            $this->assertUnit((string) $data['activity_type'], (string) $data['unit']);

            $latest = EsgEmissionFactor::query()->where('tenant_id', $tenantId)->where('code', $data['code'])->max('version');

            $factor = EsgEmissionFactor::create([
                ...$this->attributesOf($data),
                'tenant_id' => $tenantId,
                'code' => $data['code'],
                'version' => ((int) $latest) + 1,
                'is_example' => false,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->ledger->record('ESG_FACTOR_CREATED', $factor, ['code' => $factor->code, 'version' => $factor->version]);

            return $factor;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EsgEmissionFactor $factor, array $data): EsgEmissionFactor
    {
        $used = $this->usageCount($factor);
        $changesCalculation = array_key_exists('kg_co2e_per_unit', $data) && Decimal::compare((string) $data['kg_co2e_per_unit'], $factor->kg_co2e_per_unit) !== 0
            || (isset($data['unit']) && $data['unit'] !== $factor->unit)
            || (isset($data['activity_type']) && $data['activity_type'] !== $factor->activity_type);

        if ($used > 0 && $changesCalculation) {
            throw ValidationException::withMessages(['kg_co2e_per_unit' => [__('sustainability_errors.factor.in_use', ['count' => $used])]]);
        }

        $merged = [...$factor->only(['activity_type', 'unit']), ...array_intersect_key($data, array_flip(['activity_type', 'unit']))];
        $this->assertUnit((string) $merged['activity_type'], (string) $merged['unit']);

        $factor->update([
            ...$this->attributesOf([...$factor->only(['name', 'activity_type', 'unit', 'kg_co2e_per_unit', 'region', 'source', 'source_year', 'notes']), ...$data]),
            'is_active' => $data['is_active'] ?? $factor->is_active,
            'is_example' => $factor->is_example && ! $this->replacesExample($data),
        ]);

        return $factor->refresh();
    }

    /**
     * Creates the next version of a factor with a new value and source; the previous version stays valid for the
     * records that use it and is deactivated for new ones.
     *
     * @param  array<string, mixed>  $data
     */
    public function newVersion(EsgEmissionFactor $factor, array $data): EsgEmissionFactor
    {
        return DB::transaction(function () use ($factor, $data): EsgEmissionFactor {
            TransactionLock::acquire("esg_factors:{$factor->tenant_id}:{$factor->code}");

            $latest = (int) EsgEmissionFactor::query()->where('tenant_id', $factor->tenant_id)->where('code', $factor->code)->max('version');
            if ($factor->version !== $latest) {
                throw ValidationException::withMessages(['id' => [__('sustainability_errors.factor.latest_version_only')]]);
            }

            $next = EsgEmissionFactor::create([
                ...$factor->only(['name', 'activity_type', 'unit', 'scope', 'category', 'region', 'notes']),
                'tenant_id' => $factor->tenant_id,
                'code' => $factor->code,
                'version' => $latest + 1,
                'kg_co2e_per_unit' => Decimal::round((string) $data['kg_co2e_per_unit'], 8),
                'source' => $data['source'],
                'source_year' => $data['source_year'],
                'is_example' => false,
                'is_active' => true,
            ]);
            $factor->update(['is_active' => false]);

            $this->ledger->record('ESG_FACTOR_VERSIONED', $next, ['code' => $next->code, 'version' => $next->version, 'previous_version' => $factor->version]);

            return $next;
        });
    }

    /**
     * A factor nobody uses is removed; one in use is only deactivated.
     *
     * @return bool true when the factor was deleted, false when it was deactivated
     */
    public function remove(EsgEmissionFactor $factor): bool
    {
        if ($this->usageCount($factor) > 0) {
            $factor->update(['is_active' => false]);

            return false;
        }

        $factor->forceDelete();

        return true;
    }

    /**
     * The most recent active factor for an activity type and region: highest source year, then highest version. A region
     * without a factor falls back to the EU one, then to the global one.
     */
    public function resolve(string $tenantId, string $activityType, string $region, ?string $unit = null): EsgEmissionFactor
    {
        foreach (array_values(array_unique([$region, 'EU', 'GLOBAL'])) as $candidate) {
            $factor = EsgEmissionFactor::query()
                ->where('tenant_id', $tenantId)
                ->where('activity_type', $activityType)
                ->where('region', $candidate)
                ->where('is_active', true)
                ->when($unit !== null, static fn ($query) => $query->where('unit', $unit))
                ->orderByDesc('source_year')
                ->orderByDesc('version')
                ->first();

            if ($factor !== null) {
                return $factor;
            }
        }

        throw ValidationException::withMessages(['activity_type' => [__('sustainability_errors.factor.missing_for_activity', ['type' => $activityType, 'region' => $region])]]);
    }

    /**
     * Installs the example library (idempotent by code): clearly marked so the company replaces it with its own sources.
     *
     * @return int number of factors created
     */
    public function installExamples(string $tenantId): int
    {
        $created = 0;

        foreach ($this->examples() as [$code, $name, $type, $unit, $value, $region, $year]) {
            $exists = EsgEmissionFactor::query()->where('tenant_id', $tenantId)->where('code', $code)->exists();
            if ($exists) {
                continue;
            }

            EsgEmissionFactor::create([
                'tenant_id' => $tenantId,
                'code' => $code,
                'version' => 1,
                'name' => $name,
                'activity_type' => $type,
                'unit' => $unit,
                'scope' => ActivityCatalog::scopeOf($type),
                'category' => ActivityCatalog::categoryOf($type),
                'kg_co2e_per_unit' => $value,
                'region' => $region,
                'source' => self::ExampleSource,
                'source_year' => $year,
                'is_example' => true,
                'is_active' => true,
            ]);
            $created++;
        }

        return $created;
    }

    private function usageCount(EsgEmissionFactor $factor): int
    {
        return EsgActivityRecord::query()->where('tenant_id', $factor->tenant_id)->where('emission_factor_id', $factor->id)->count();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replacesExample(array $data): bool
    {
        return isset($data['source']) && ! str_starts_with((string) $data['source'], 'EXAMPLE');
    }

    private function assertUnit(string $activityType, string $unit): void
    {
        if (! ActivityCatalog::allows($activityType, $unit)) {
            throw ValidationException::withMessages(['unit' => [__('sustainability_errors.factor.unit_not_allowed', ['unit' => $unit, 'type' => $activityType])]]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesOf(array $data): array
    {
        return [
            'name' => $data['name'],
            'activity_type' => $data['activity_type'],
            'unit' => $data['unit'],
            'scope' => ActivityCatalog::scopeOf((string) $data['activity_type']),
            'category' => ActivityCatalog::categoryOf((string) $data['activity_type']),
            'kg_co2e_per_unit' => Decimal::round((string) $data['kg_co2e_per_unit'], 8),
            'region' => $data['region'] ?? 'EU',
            'source' => $data['source'],
            'source_year' => $data['source_year'],
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int}>
     */
    private function examples(): array
    {
        return [
            ['EX-ELEC-IT', 'Electricity grid, Italy (location-based)', 'electricity_grid', 'kWh', '0.25000000', 'IT', 2023],
            ['EX-ELEC-CH', 'Electricity grid, Switzerland (consumption mix)', 'electricity_grid', 'kWh', '0.02800000', 'CH', 2023],
            ['EX-ELEC-EU', 'Electricity grid, EU average', 'electricity_grid', 'kWh', '0.23000000', 'EU', 2023],
            ['EX-GAS-EU', 'Natural gas, stationary combustion', 'natural_gas', 'm3', '2.00000000', 'EU', 2023],
            ['EX-DIESEL-EU', 'Diesel, mobile combustion', 'fuel_diesel', 'l', '2.67000000', 'EU', 2023],
            ['EX-PETROL-EU', 'Petrol, mobile combustion', 'fuel_petrol', 'l', '2.31000000', 'EU', 2023],
            ['EX-CAR-EU', 'Business travel by average car', 'business_travel_car', 'km', '0.17000000', 'EU', 2023],
            ['EX-AIR-EU', 'Business travel by air, short and medium haul', 'business_travel_air', 'km', '0.15000000', 'EU', 2023],
            ['EX-RAIL-EU', 'Business travel by rail', 'business_travel_rail', 'km', '0.04000000', 'EU', 2023],
            ['EX-GOODS-EUR', 'Purchased goods and services, spend-based (EUR)', 'purchased_goods', 'EUR', '0.40000000', 'EU', 2023],
            ['EX-GOODS-CHF', 'Purchased goods and services, spend-based (CHF)', 'purchased_goods', 'CHF', '0.42000000', 'CH', 2023],
            ['EX-WASTE-EU', 'Mixed waste treatment', 'waste', 'kg', '0.45000000', 'EU', 2023],
            ['EX-WATER-EU', 'Water supply and treatment', 'water', 'm3', '0.34400000', 'EU', 2023],
        ];
    }
}
