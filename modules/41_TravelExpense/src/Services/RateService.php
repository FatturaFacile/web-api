<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Services;

use Illuminate\Validation\ValidationException;
use Modules\TravelExpense\Models\TravelMileageRate;
use Modules\TravelExpense\Models\TravelPerDiemRate;

/**
 * The per-diem and mileage rate tables of a company: one row per jurisdiction, year, scope and country (per diem) or
 * jurisdiction, year and vehicle type (mileage). Lookups fall back to the latest earlier year, so a table does not
 * have to be re-entered every January.
 */
final readonly class RateService
{
    public function __construct(private TravelLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPerDiemRate(string $tenantId, array $data): TravelPerDiemRate
    {
        $this->assertPerDiemRateIsFree($data);

        $rate = TravelPerDiemRate::create($this->perDiemAttributes($data) + ['tenant_id' => $tenantId]);
        $this->ledger->record('TRAVEL_PER_DIEM_RATE_CREATED', $rate, ['jurisdiction' => $rate->jurisdiction, 'year' => $rate->year, 'scope' => $rate->scope]);

        return $rate;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePerDiemRate(TravelPerDiemRate $rate, array $data): TravelPerDiemRate
    {
        $this->assertPerDiemRateIsFree($data, $rate->id);
        $rate->update($this->perDiemAttributes($data));

        return $rate->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createMileageRate(string $tenantId, array $data): TravelMileageRate
    {
        $this->assertMileageRateIsFree($data);

        $rate = TravelMileageRate::create($this->mileageAttributes($data) + ['tenant_id' => $tenantId]);
        $this->ledger->record('TRAVEL_MILEAGE_RATE_CREATED', $rate, ['jurisdiction' => $rate->jurisdiction, 'year' => $rate->year, 'vehicle_type' => $rate->vehicle_type]);

        return $rate;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMileageRate(TravelMileageRate $rate, array $data): TravelMileageRate
    {
        $this->assertMileageRateIsFree($data, $rate->id);
        $rate->update($this->mileageAttributes($data));

        return $rate->refresh();
    }

    /**
     * Adds the reference rates of a jurisdiction and year that the company does not have yet. The values are the
     * statutory or customary ones (Italy: art. 51 TUIR tax-free thresholds; Switzerland: flat daily rate of CHF 75 with
     * breakfast 15, lunch 30 and dinner 30) and are meant to be reviewed against the policy of the company.
     *
     * @return array{per_diem_created: int, mileage_created: int}
     */
    public function seedDefaults(string $tenantId, string $jurisdiction, int $year): array
    {
        $note = 'Reference value: confirm it against the current regulation and the policy of the company.';
        $perDiemCreated = 0;
        $mileageCreated = 0;

        $perDiem = $jurisdiction === 'CH'
            ? [
                ['scope' => TravelPerDiemRate::Domestic, 'daily_amount' => '75.00', 'tax_free_daily_amount' => null, 'lodging_deduction_percent' => '0', 'breakfast_deduction_percent' => '20', 'meal_deduction_percent' => '40', 'deduct_each_meal' => true],
                ['scope' => TravelPerDiemRate::Abroad, 'daily_amount' => '75.00', 'tax_free_daily_amount' => null, 'lodging_deduction_percent' => '0', 'breakfast_deduction_percent' => '20', 'meal_deduction_percent' => '40', 'deduct_each_meal' => true],
            ]
            : [
                ['scope' => TravelPerDiemRate::Domestic, 'daily_amount' => '46.48', 'tax_free_daily_amount' => '46.48', 'lodging_deduction_percent' => '33.3333', 'breakfast_deduction_percent' => '0', 'meal_deduction_percent' => '33.3333', 'deduct_each_meal' => false],
                ['scope' => TravelPerDiemRate::Abroad, 'daily_amount' => '77.47', 'tax_free_daily_amount' => '77.47', 'lodging_deduction_percent' => '33.3333', 'breakfast_deduction_percent' => '0', 'meal_deduction_percent' => '33.3333', 'deduct_each_meal' => false],
            ];
        $currency = $jurisdiction === 'CH' ? 'CHF' : 'EUR';

        foreach ($perDiem as $row) {
            $exists = TravelPerDiemRate::query()->where('tenant_id', $tenantId)->where('jurisdiction', $jurisdiction)->where('year', $year)->where('scope', $row['scope'])->whereNull('country')->exists();

            if (! $exists) {
                TravelPerDiemRate::create($row + ['tenant_id' => $tenantId, 'jurisdiction' => $jurisdiction, 'year' => $year, 'country' => null, 'currency' => $currency, 'notes' => $note]);
                $perDiemCreated++;
            }
        }

        $mileage = $jurisdiction === 'CH' ? ['car' => '0.7000', 'motorcycle' => '0.4000'] : ['car' => '0.4000', 'motorcycle' => '0.2000'];

        foreach ($mileage as $vehicle => $ratePerKm) {
            $exists = TravelMileageRate::query()->where('tenant_id', $tenantId)->where('jurisdiction', $jurisdiction)->where('year', $year)->where('vehicle_type', $vehicle)->exists();

            if (! $exists) {
                TravelMileageRate::create(['tenant_id' => $tenantId, 'jurisdiction' => $jurisdiction, 'year' => $year, 'vehicle_type' => $vehicle, 'currency' => $currency, 'rate_per_km' => $ratePerKm, 'notes' => $note]);
                $mileageCreated++;
            }
        }

        return ['per_diem_created' => $perDiemCreated, 'mileage_created' => $mileageCreated];
    }

    /**
     * The rate for a day: the table of the latest year not after the day, the destination country over the generic
     * row of the scope. Null when the company has no matching row.
     */
    public function findPerDiemRate(string $tenantId, string $jurisdiction, int $year, string $scope, string $country): ?TravelPerDiemRate
    {
        $candidates = TravelPerDiemRate::query()
            ->where('tenant_id', $tenantId)
            ->where('jurisdiction', $jurisdiction)
            ->where('scope', $scope)
            ->where('year', '<=', $year)
            ->where(static fn ($query) => $query->where('country', $country)->orWhereNull('country'))
            ->get();

        $bestYear = $candidates->max('year');

        if ($bestYear === null) {
            return null;
        }

        $ofYear = $candidates->where('year', $bestYear);

        return $ofYear->firstWhere('country', $country) ?? $ofYear->firstWhere('country', null);
    }

    public function findMileageRate(string $tenantId, string $jurisdiction, int $year, string $vehicleType): ?TravelMileageRate
    {
        return TravelMileageRate::query()
            ->where('tenant_id', $tenantId)
            ->where('jurisdiction', $jurisdiction)
            ->where('vehicle_type', $vehicleType)
            ->where('year', '<=', $year)
            ->orderByDesc('year')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertPerDiemRateIsFree(array $data, ?string $ignoreId = null): void
    {
        $exists = TravelPerDiemRate::query()
            ->where('jurisdiction', $data['jurisdiction'])
            ->where('year', $data['year'])
            ->where('scope', $data['scope'])
            ->when(($data['country'] ?? null) === null, static fn ($query) => $query->whereNull('country'), static fn ($query) => $query->where('country', $data['country']))
            ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['year' => __('travel_errors.rate.per_diem_exists')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertMileageRateIsFree(array $data, ?string $ignoreId = null): void
    {
        $exists = TravelMileageRate::query()
            ->where('jurisdiction', $data['jurisdiction'])
            ->where('year', $data['year'])
            ->where('vehicle_type', $data['vehicle_type'])
            ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['year' => __('travel_errors.rate.mileage_exists')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function perDiemAttributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip([
            'jurisdiction', 'year', 'scope', 'country', 'currency', 'daily_amount', 'tax_free_daily_amount', 'lodging_deduction_percent',
            'breakfast_deduction_percent', 'meal_deduction_percent', 'deduct_each_meal', 'half_day_min_hours', 'full_day_min_hours', 'notes',
        ]));

        return array_filter(
            $attributes,
            static fn (mixed $value, string $key): bool => $value !== null || in_array($key, ['country', 'tax_free_daily_amount', 'notes'], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mileageAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip(['jurisdiction', 'year', 'vehicle_type', 'currency', 'rate_per_km', 'notes']));
    }
}
