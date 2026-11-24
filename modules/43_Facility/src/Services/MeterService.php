<?php

declare(strict_types=1);

namespace Modules\Facility\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Facility\Models\FacilityMeter;
use Modules\Facility\Models\FacilityMeterReading;
use Modules\Facility\Support\Decimal;

/**
 * Utility meters: readings in chronological order with the consumption since the previous reading, costed with the
 * tariff of the meter, and a monthly summary.
 *
 * The first reading of a meter is its baseline (zero consumption). A reading below the previous one is refused unless it
 * is declared a roll-over of the dial (the meter must have a roll-over value) or the replacement of the meter (the new
 * meter starts from zero, so its first value is the consumption). The consumption of a reading counts in the month of
 * its reading date.
 */
final readonly class MeterService
{
    private const int ValueScale = 3;

    public function __construct(private FacilityLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createMeter(array $data): FacilityMeter
    {
        $this->assertUnit((string) $data['kind'], (string) $data['unit']);

        return FacilityMeter::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMeter(FacilityMeter $meter, array $data): FacilityMeter
    {
        $this->assertUnit((string) ($data['kind'] ?? $meter->kind), (string) ($data['unit'] ?? $meter->unit));

        if ((isset($data['kind']) && $data['kind'] !== $meter->kind) || (isset($data['unit']) && $data['unit'] !== $meter->unit)) {
            if ($meter->readings()->exists()) {
                throw ValidationException::withMessages(['unit' => [__('facility_errors.meter.unit_locked')]]);
            }
        }

        $meter->update($data);

        return $meter->refresh();
    }

    /**
     * @param  array{read_on: string, value: string|int|float, reading_type?: string|null, notes?: string|null}  $data
     */
    public function recordReading(FacilityMeter $meter, array $data, ?string $actorId): FacilityMeterReading
    {
        return DB::transaction(function () use ($meter, $data, $actorId): FacilityMeterReading {
            $locked = FacilityMeter::query()->whereKey($meter->id)->lockForUpdate()->firstOrFail();
            $type = (string) ($data['reading_type'] ?? FacilityMeterReading::Regular);
            $value = Decimal::of($data['value'], self::ValueScale);

            if (! $locked->is_active) {
                throw ValidationException::withMessages(['meter' => [__('facility_errors.meter.inactive')]]);
            }

            if ($locked->last_read_on !== null && $data['read_on'] <= $locked->last_read_on->toDateString()) {
                throw ValidationException::withMessages(['read_on' => [__('facility_errors.meter.not_chronological', ['date' => $locked->last_read_on->toDateString()])]]);
            }

            $consumption = $this->consumption($locked, $value, $type);
            $tariff = $locked->tariff_per_unit;

            $reading = FacilityMeterReading::create([
                'meter_id' => $locked->id,
                'read_on' => $data['read_on'],
                'value' => $value,
                'consumption' => $consumption,
                'cost' => $tariff === null ? null : Decimal::mul($consumption, (string) $tariff, 2),
                'currency' => $tariff === null ? null : $locked->currency,
                'reading_type' => $type,
                'recorded_by' => $actorId,
                'notes' => $data['notes'] ?? null,
            ]);

            $locked->update(['last_read_on' => $data['read_on'], 'last_value' => $value]);
            $this->ledger->record('FACILITY_METER_READING_RECORDED', $reading, ['meter_id' => $locked->id, 'type' => $type]);

            return $reading;
        });
    }

    /**
     * Removes the latest reading of a meter (a wrong entry); earlier readings stay because later deltas depend on them.
     */
    public function deleteLatestReading(FacilityMeter $meter, FacilityMeterReading $reading): void
    {
        DB::transaction(function () use ($meter, $reading): void {
            $locked = FacilityMeter::query()->whereKey($meter->id)->lockForUpdate()->firstOrFail();
            $latest = $locked->readings()->orderByDesc('read_on')->first();

            if ($latest === null || (string) $latest->id !== (string) $reading->id) {
                throw ValidationException::withMessages(['reading' => [__('facility_errors.meter.only_latest')]]);
            }

            $reading->delete();
            $previous = $locked->readings()->orderByDesc('read_on')->first();
            $locked->update(['last_read_on' => $previous?->read_on?->toDateString(), 'last_value' => $previous?->value]);
            $this->ledger->record('FACILITY_METER_READING_DELETED', $reading, ['meter_id' => $locked->id]);
        });
    }

    /**
     * Consumption and cost per month, meter kind and unit for a calendar year.
     *
     * @return array{year: int, rows: list<array{month: string, kind: string, unit: string, currency: string|null, consumption: string, cost: string|null, readings: int}>, totals: list<array{kind: string, unit: string, currency: string|null, consumption: string, cost: string|null}>}
     */
    public function monthlySummary(int $year, ?string $buildingId = null, ?string $kind = null): array
    {
        $meters = FacilityMeter::query()
            ->when($buildingId !== null, static fn ($query) => $query->where('building_id', $buildingId))
            ->when($kind !== null, static fn ($query) => $query->where('kind', $kind))
            ->get(['id', 'kind', 'unit', 'currency'])
            ->keyBy('id');

        $readings = FacilityMeterReading::query()
            ->whereIn('meter_id', $meters->keys())
            ->whereDate('read_on', '>=', sprintf('%d-01-01', $year))
            ->whereDate('read_on', '<=', sprintf('%d-12-31', $year))
            ->orderBy('read_on')
            ->get(['meter_id', 'read_on', 'consumption', 'cost', 'currency']);

        $rows = [];
        $totals = [];

        foreach ($readings as $reading) {
            $meter = $meters[$reading->meter_id];
            $month = $reading->read_on->format('Y-m');
            $currency = $reading->cost === null ? null : (string) $reading->currency;
            $rowKey = "{$month}|{$meter->kind}|{$meter->unit}|{$currency}";
            $totalKey = "{$meter->kind}|{$meter->unit}|{$currency}";

            $rows[$rowKey] ??= ['month' => $month, 'kind' => $meter->kind, 'unit' => $meter->unit, 'currency' => $currency, 'consumption' => '0.000', 'cost' => $currency === null ? null : '0.00', 'readings' => 0];
            $totals[$totalKey] ??= ['kind' => $meter->kind, 'unit' => $meter->unit, 'currency' => $currency, 'consumption' => '0.000', 'cost' => $currency === null ? null : '0.00'];

            $rows[$rowKey]['consumption'] = Decimal::add($rows[$rowKey]['consumption'], (string) $reading->consumption, self::ValueScale);
            $totals[$totalKey]['consumption'] = Decimal::add($totals[$totalKey]['consumption'], (string) $reading->consumption, self::ValueScale);
            $rows[$rowKey]['readings']++;

            if ($reading->cost !== null) {
                $rows[$rowKey]['cost'] = Decimal::add((string) $rows[$rowKey]['cost'], (string) $reading->cost, 2);
                $totals[$totalKey]['cost'] = Decimal::add((string) $totals[$totalKey]['cost'], (string) $reading->cost, 2);
            }
        }

        return ['year' => $year, 'rows' => array_values($rows), 'totals' => array_values($totals)];
    }

    private function consumption(FacilityMeter $meter, string $value, string $type): string
    {
        if ($meter->last_value === null) {
            return Decimal::of('0', self::ValueScale);
        }

        $previous = (string) $meter->last_value;

        return match ($type) {
            FacilityMeterReading::Replacement => $value,
            FacilityMeterReading::Rollover => $this->rolloverConsumption($meter, $previous, $value),
            default => $this->regularConsumption($previous, $value),
        };
    }

    private function regularConsumption(string $previous, string $value): string
    {
        if (Decimal::compare($value, $previous, self::ValueScale) < 0) {
            throw ValidationException::withMessages(['value' => [__('facility_errors.meter.below_previous', ['previous' => $previous])]]);
        }

        return Decimal::sub($value, $previous, self::ValueScale);
    }

    private function rolloverConsumption(FacilityMeter $meter, string $previous, string $value): string
    {
        if ($meter->rollover_value === null) {
            throw ValidationException::withMessages(['reading_type' => [__('facility_errors.meter.no_rollover_value')]]);
        }

        if (Decimal::compare($value, $previous, self::ValueScale) >= 0) {
            throw ValidationException::withMessages(['value' => [__('facility_errors.meter.rollover_not_below', ['previous' => $previous])]]);
        }

        if (Decimal::compare($previous, (string) $meter->rollover_value, self::ValueScale) > 0) {
            throw ValidationException::withMessages(['value' => [__('facility_errors.meter.above_rollover', ['rollover' => (string) $meter->rollover_value])]]);
        }

        return Decimal::add(Decimal::sub((string) $meter->rollover_value, $previous, self::ValueScale), $value, self::ValueScale);
    }

    private function assertUnit(string $kind, string $unit): void
    {
        if (! in_array($unit, FacilityMeter::Units[$kind] ?? [], true)) {
            throw ValidationException::withMessages(['unit' => [__('facility_errors.meter.unit_for_kind', ['unit' => $unit, 'kind' => $kind])]]);
        }
    }
}
