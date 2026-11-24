<?php

declare(strict_types=1);

namespace Modules\Sustainability\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Sustainability\Models\EsgActivityRecord;
use Modules\Sustainability\Models\EsgEmissionFactor;
use Modules\Sustainability\Support\ActivityCatalog;
use Modules\Sustainability\Support\Decimal;

/**
 * Activity data capture. CO2e (kg) = quantity x factor, computed with exact decimals, and the factor (code, version,
 * value, source) is frozen on the record. Data of a closed reporting period is locked.
 */
final readonly class ActivityDataService
{
    public function __construct(
        private EmissionFactorService $factors,
        private ReportingPeriodService $periods,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $actorId): EsgActivityRecord
    {
        return DB::transaction(function () use ($tenantId, $data, $actorId): EsgActivityRecord {
            $this->assertUnlocked($tenantId, (string) $data['activity_date']);
            $factor = $this->factorFor($tenantId, $data);

            $record = EsgActivityRecord::create([
                ...$this->frozenValues($factor, (string) $data['quantity'], (string) $data['unit']),
                'tenant_id' => $tenantId,
                'activity_date' => $data['activity_date'],
                'data_quality' => $data['data_quality'] ?? 'measured',
                'site' => $data['site'] ?? null,
                'reference' => $data['reference'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actorId,
            ]);

            return $record;
        });
    }

    /**
     * Records several lines in one transaction (a meter reading file, a month of fuel cards).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<EsgActivityRecord>
     */
    public function createMany(string $tenantId, array $lines, ?string $actorId): array
    {
        return DB::transaction(function () use ($tenantId, $lines, $actorId): array {
            $records = [];
            foreach ($lines as $index => $line) {
                try {
                    $records[] = $this->create($tenantId, $line, $actorId);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(static fn (array $messages, string $field): array => ["lines.{$index}.{$field}" => $messages])->all());
                }
            }

            return $records;
        });
    }

    /**
     * The factor is frozen again from the current (or newly chosen) factor only when the quantity, the unit, the date or
     * the factor change; changing notes or the quality flag keeps the frozen values.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(EsgActivityRecord $record, array $data): EsgActivityRecord
    {
        return DB::transaction(function () use ($record, $data): EsgActivityRecord {
            $tenantId = (string) $record->tenant_id;
            $this->assertUnlocked($tenantId, $record->activity_date->toDateString());
            if (isset($data['activity_date'])) {
                $this->assertUnlocked($tenantId, (string) $data['activity_date']);
            }

            $changes = array_intersect_key($data, array_flip(['activity_date', 'data_quality', 'site', 'reference', 'notes']));
            $recalculates = isset($data['quantity']) || isset($data['unit']) || isset($data['emission_factor_id']);

            if ($recalculates) {
                $factor = isset($data['emission_factor_id'])
                    ? $this->factorFor($tenantId, [...$data, 'activity_type' => $record->activity_type, 'unit' => $data['unit'] ?? $record->unit])
                    : EsgEmissionFactor::query()->where('tenant_id', $tenantId)->findOrFail($record->emission_factor_id);
                $changes += $this->frozenValues($factor, (string) ($data['quantity'] ?? $record->quantity), (string) ($data['unit'] ?? $record->unit));
            }

            $record->update($changes);

            return $record->refresh();
        });
    }

    public function delete(EsgActivityRecord $record): void
    {
        $this->assertUnlocked((string) $record->tenant_id, $record->activity_date->toDateString());
        $record->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function factorFor(string $tenantId, array $data): EsgEmissionFactor
    {
        if (isset($data['emission_factor_id'])) {
            $factor = EsgEmissionFactor::query()->where('tenant_id', $tenantId)->findOrFail($data['emission_factor_id']);
            if (! $factor->is_active) {
                throw ValidationException::withMessages(['emission_factor_id' => [__('sustainability_errors.factor.inactive')]]);
            }
            if (isset($data['activity_type']) && $data['activity_type'] !== $factor->activity_type) {
                throw ValidationException::withMessages(['activity_type' => [__('sustainability_errors.factor.type_mismatch', ['expected' => $factor->activity_type, 'type' => $data['activity_type']])]]);
            }

            return $factor;
        }

        $type = (string) $data['activity_type'];
        if (! ActivityCatalog::allows($type, (string) $data['unit'])) {
            throw ValidationException::withMessages(['unit' => [__('sustainability_errors.factor.unit_not_allowed', ['unit' => $data['unit'], 'type' => $type])]]);
        }

        return $this->factors->resolve($tenantId, $type, (string) ($data['region'] ?? 'EU'), (string) $data['unit']);
    }

    /**
     * @return array<string, mixed>
     */
    private function frozenValues(EsgEmissionFactor $factor, string $quantity, string $unit): array
    {
        if ($factor->unit !== $unit) {
            throw ValidationException::withMessages(['unit' => [__('sustainability_errors.factor.unit_mismatch', ['factor_unit' => $factor->unit, 'unit' => $unit])]]);
        }

        return [
            'emission_factor_id' => $factor->id,
            'quantity' => Decimal::round($quantity, 4),
            'unit' => $unit,
            'activity_type' => $factor->activity_type,
            'scope' => $factor->scope,
            'category' => $factor->category,
            'factor_code' => $factor->code,
            'factor_version' => $factor->version,
            'factor_kg_co2e_per_unit' => $factor->kg_co2e_per_unit,
            'factor_source' => $factor->source,
            'co2e_kg' => Decimal::round(Decimal::mul($quantity, $factor->kg_co2e_per_unit), 4),
        ];
    }

    private function assertUnlocked(string $tenantId, string $date): void
    {
        $period = $this->periods->closedPeriodOn($tenantId, $date);
        if ($period !== null) {
            throw ValidationException::withMessages(['activity_date' => [__('sustainability_errors.activity.locked', ['name' => $period->name])]]);
        }
    }
}
