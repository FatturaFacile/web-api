<?php

declare(strict_types=1);

namespace Modules\Sustainability\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Sustainability\Models\EsgKpiDefinition;
use Modules\Sustainability\Models\EsgKpiValue;
use Modules\Sustainability\Models\EsgReportingPeriod;
use Modules\Sustainability\Support\Decimal;

/**
 * ESG indicator definitions (environmental, social, governance) and their value per reporting period. A closed period
 * locks its values; one value exists per indicator and period.
 */
final readonly class KpiService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createDefinition(string $tenantId, array $data): EsgKpiDefinition
    {
        return EsgKpiDefinition::create([
            'tenant_id' => $tenantId,
            ...$this->definitionAttributes($data),
            'code' => $data['code'],
            'is_example' => false,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDefinition(EsgKpiDefinition $definition, array $data): EsgKpiDefinition
    {
        $definition->update(array_intersect_key($data, array_flip(['name', 'pillar', 'unit', 'direction', 'esrs_reference', 'target_value', 'description', 'is_active'])));

        return $definition->refresh();
    }

    /**
     * Installs the example ESRS-oriented indicators (idempotent by code).
     *
     * @return int number of definitions created
     */
    public function installDefaults(string $tenantId): int
    {
        $created = 0;

        foreach ($this->defaults() as [$code, $name, $pillar, $unit, $direction, $reference]) {
            if (EsgKpiDefinition::query()->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
                continue;
            }

            EsgKpiDefinition::create([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $name,
                'pillar' => $pillar,
                'unit' => $unit,
                'direction' => $direction,
                'esrs_reference' => $reference,
                'is_example' => true,
                'is_active' => true,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(string $tenantId, EsgKpiDefinition $definition, EsgReportingPeriod $period, array $data, ?string $actorId): EsgKpiValue
    {
        if (! $definition->is_active) {
            throw ValidationException::withMessages(['kpi_definition_id' => [__('sustainability_errors.kpi.inactive')]]);
        }

        if (! $period->isOpen()) {
            throw ValidationException::withMessages(['reporting_period_id' => [__('sustainability_errors.kpi.period_closed', ['name' => $period->name])]]);
        }

        return DB::transaction(function () use ($tenantId, $definition, $period, $data, $actorId): EsgKpiValue {
            $value = EsgKpiValue::query()->where('tenant_id', $tenantId)->where('kpi_definition_id', $definition->id)->where('reporting_period_id', $period->id)->first();
            $attributes = [
                'value' => Decimal::round((string) $data['value'], 4),
                'data_quality' => $data['data_quality'] ?? 'measured',
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actorId,
            ];

            if ($value === null) {
                return EsgKpiValue::create([...$attributes, 'tenant_id' => $tenantId, 'kpi_definition_id' => $definition->id, 'reporting_period_id' => $period->id]);
            }

            $value->update($attributes);

            return $value->refresh();
        });
    }

    /**
     * Whether a value meets the target of its indicator; null without a target.
     */
    public function targetMet(EsgKpiDefinition $definition, string $value): ?bool
    {
        if ($definition->target_value === null) {
            return null;
        }

        $comparison = Decimal::compare($value, (string) $definition->target_value);

        return $definition->direction === 'higher_is_better' ? $comparison >= 0 : $comparison <= 0;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function definitionAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'pillar' => $data['pillar'],
            'unit' => $data['unit'],
            'direction' => $data['direction'] ?? 'lower_is_better',
            'esrs_reference' => $data['esrs_reference'] ?? null,
            'target_value' => $data['target_value'] ?? null,
            'description' => $data['description'] ?? null,
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string|null}>
     */
    private function defaults(): array
    {
        return [
            ['energy_consumption', 'Total energy consumption', 'environmental', 'MWh', 'lower_is_better', 'ESRS E1-5'],
            ['renewable_energy_share', 'Share of renewable energy', 'environmental', '%', 'higher_is_better', 'ESRS E1-5'],
            ['water_consumption', 'Water consumption', 'environmental', 'm3', 'lower_is_better', 'ESRS E3-4'],
            ['waste_recycling_rate', 'Waste diverted from disposal', 'environmental', '%', 'higher_is_better', 'ESRS E5-5'],
            ['headcount', 'Headcount at period end', 'social', 'persons', 'higher_is_better', 'ESRS S1-6'],
            ['female_employees_share', 'Female share of employees', 'social', '%', 'higher_is_better', 'ESRS S1-9'],
            ['recordable_accidents', 'Recordable work-related accidents', 'social', 'count', 'lower_is_better', 'ESRS S1-14'],
            ['accident_frequency_rate', 'Accident frequency rate (per million hours worked)', 'social', 'rate', 'lower_is_better', 'ESRS S1-14'],
            ['training_hours_per_employee', 'Training hours per employee', 'social', 'hours', 'higher_is_better', 'ESRS S1-13'],
            ['gender_pay_gap', 'Gender pay gap', 'social', '%', 'lower_is_better', 'ESRS S1-16'],
            ['independent_board_share', 'Independent members of the board', 'governance', '%', 'higher_is_better', 'ESRS G1'],
            ['anti_corruption_training_coverage', 'Staff trained on anti-corruption', 'governance', '%', 'higher_is_better', 'ESRS G1-3'],
            ['corruption_incidents', 'Confirmed incidents of corruption', 'governance', 'count', 'lower_is_better', 'ESRS G1-4'],
            ['supplier_code_coverage', 'Suppliers covered by the code of conduct', 'governance', '%', 'higher_is_better', 'ESRS G1-2'],
        ];
    }
}
