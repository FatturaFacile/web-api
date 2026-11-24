<?php

declare(strict_types=1);

namespace Modules\Planning\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Planning\Models\PlanningCapacityConstraint;
use Modules\Planning\Models\PlanningPlanLine;
use Modules\Planning\Models\PlanningScenario;

/**
 * Scenarios: the baseline, its what-if copies and the comparison between two of them. Only a draft scenario is edited
 * or deleted.
 */
final readonly class ScenarioService
{
    private const int MaxMonths = 36;

    public function __construct(private BalanceService $balance, private CapacityService $capacity) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): PlanningScenario
    {
        $this->assertBaselineAvailable($tenantId, (string) ($data['scenario_type'] ?? PlanningScenario::Baseline), null);
        $this->assertSpan((string) $data['horizon_start'], (string) $data['horizon_end']);

        return PlanningScenario::create([
            ...$this->fields($data),
            'tenant_id' => $tenantId,
            'scenario_type' => $data['scenario_type'] ?? PlanningScenario::Baseline,
            'status' => PlanningScenario::Draft,
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PlanningScenario $scenario, array $data): PlanningScenario
    {
        $this->assertDraft($scenario);
        $merged = [...$scenario->only(['scenario_type', 'horizon_start', 'horizon_end']), ...$data];

        if (isset($data['scenario_type'])) {
            $this->assertBaselineAvailable($scenario->tenant_id, (string) $data['scenario_type'], $scenario->id);
        }

        if (strtotime((string) $merged['horizon_end']) < strtotime((string) $merged['horizon_start'])) {
            throw ValidationException::withMessages(['horizon_end' => [__('planning_errors.scenario.horizon_order')]]);
        }

        $this->assertSpan((string) $merged['horizon_start'], (string) $merged['horizon_end']);
        $scenario->fill($this->fields($data));

        if ($scenario->isDirty(['horizon_start', 'horizon_end'])) {
            $outside = $scenario->lines()->get()->contains(static fn (PlanningPlanLine $line): bool => ! PlanningPeriods::covers($scenario, $line->period));

            if ($outside) {
                throw ValidationException::withMessages(['horizon_end' => [__('planning_errors.scenario.lines_outside_horizon')]]);
            }
        }

        $scenario->save();

        return $scenario->refresh();
    }

    public function delete(PlanningScenario $scenario): void
    {
        $this->assertDraft($scenario);

        if ($scenario->published_version > 0) {
            throw ValidationException::withMessages(['status' => [__('planning_errors.scenario.published')]]);
        }

        DB::transaction(function () use ($scenario): void {
            $scenario->lines()->get()->each->delete();
            $scenario->constraints()->get()->each->delete();
            $scenario->delete();
        });
    }

    /**
     * A what-if copy of a scenario: lines and capacity constraints are copied (demand and supply may be scaled by a
     * percentage), the cycle starts over in draft.
     *
     * @param  array<string, mixed>  $data
     */
    public function copy(PlanningScenario $source, array $data): PlanningScenario
    {
        $demandFactor = $this->factor($data['demand_adjustment_percent'] ?? null);
        $supplyFactor = $this->factor($data['supply_adjustment_percent'] ?? null);

        return DB::transaction(function () use ($source, $data, $demandFactor, $supplyFactor): PlanningScenario {
            $copy = PlanningScenario::create([
                'tenant_id' => $source->tenant_id,
                'name' => $data['name'],
                'description' => $data['description'] ?? $source->description,
                'scenario_type' => PlanningScenario::WhatIf,
                'base_scenario_id' => $source->id,
                'status' => PlanningScenario::Draft,
                'horizon_start' => $source->horizon_start->toDateString(),
                'horizon_end' => $source->horizon_end->toDateString(),
            ]);

            foreach ($source->lines()->get() as $line) {
                $factor = $line->line_type === PlanningPlanLine::Demand ? $demandFactor : $supplyFactor;
                PlanningPlanLine::create([
                    'tenant_id' => $source->tenant_id,
                    'scenario_id' => $copy->id,
                    'line_type' => $line->line_type,
                    'item_id' => $line->item_id,
                    'item_code' => $line->item_code,
                    'item_name' => $line->item_name,
                    'period' => $line->period->toDateString(),
                    'quantity' => bcround(bcmul((string) $line->quantity, $factor, 6), 3),
                    'source' => PlanningPlanLine::Copied,
                    'notes' => $line->notes,
                ]);
            }

            foreach ($source->constraints()->get() as $constraint) {
                PlanningCapacityConstraint::create([
                    'tenant_id' => $source->tenant_id,
                    'scenario_id' => $copy->id,
                    'resource_id' => $constraint->resource_id,
                    'period' => $constraint->period->toDateString(),
                    'available_capacity' => $constraint->available_capacity,
                    'notes' => $constraint->notes,
                ]);
            }

            return $copy->refresh();
        });
    }

    /**
     * Side-by-side totals of two scenarios per item and the capacity load of both.
     *
     * @return array<string, mixed>
     */
    public function compare(PlanningScenario $base, PlanningScenario $other): array
    {
        $baseBalance = $this->balance->balance($base, true);
        $otherBalance = $this->balance->balance($other, true);
        $baseItems = collect($baseBalance['items'])->keyBy('item_code');
        $otherItems = collect($otherBalance['items'])->keyBy('item_code');
        $zero = ['total_demand' => '0.000', 'total_supply' => '0.000', 'total_gap' => '0.000', 'ending_gap' => '0.000', 'max_shortage' => '0.000'];

        $items = $baseItems->keys()->merge($otherItems->keys())->unique()->sort()->values()->map(function (string $code) use ($baseItems, $otherItems, $zero): array {
            $left = $baseItems->get($code, $zero);
            $right = $otherItems->get($code, $zero);

            return [
                'item_code' => $code,
                'item_name' => $right['item_name'] ?? $left['item_name'] ?? null,
                'base' => $this->summaryOf($left),
                'other' => $this->summaryOf($right),
                'delta' => [
                    'total_demand' => bcsub($right['total_demand'], $left['total_demand'], 3),
                    'total_supply' => bcsub($right['total_supply'], $left['total_supply'], 3),
                    'total_gap' => bcsub($right['total_gap'], $left['total_gap'], 3),
                    'max_shortage' => bcsub($right['max_shortage'], $left['max_shortage'], 3),
                ],
            ];
        })->all();

        $baseCapacity = $this->capacity->check($base);
        $otherCapacity = $this->capacity->check($other);

        return [
            'base' => ['id' => $base->id, 'name' => $base->name, 'status' => $base->status, 'totals' => $baseBalance['totals'], 'capacity' => $baseCapacity['summary']],
            'other' => ['id' => $other->id, 'name' => $other->name, 'status' => $other->status, 'totals' => $otherBalance['totals'], 'capacity' => $otherCapacity['summary']],
            'delta' => [
                'total_demand' => bcsub($otherBalance['totals']['total_demand'], $baseBalance['totals']['total_demand'], 3),
                'total_supply' => bcsub($otherBalance['totals']['total_supply'], $baseBalance['totals']['total_supply'], 3),
                'total_gap' => bcsub($otherBalance['totals']['total_gap'], $baseBalance['totals']['total_gap'], 3),
                'overloaded_periods' => $otherCapacity['summary']['overloaded_count'] - $baseCapacity['summary']['overloaded_count'],
            ],
            'items' => $items,
        ];
    }

    public function assertDraft(PlanningScenario $scenario): void
    {
        if (! $scenario->isDraft()) {
            throw ValidationException::withMessages(['status' => [__('planning_errors.scenario.not_draft')]]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function summaryOf(array $row): array
    {
        return [
            'total_demand' => $row['total_demand'],
            'total_supply' => $row['total_supply'],
            'total_gap' => $row['total_gap'],
            'max_shortage' => $row['max_shortage'],
        ];
    }

    private function assertBaselineAvailable(string $tenantId, string $type, ?string $ignoreId): void
    {
        if ($type !== PlanningScenario::Baseline) {
            return;
        }

        $exists = PlanningScenario::query()
            ->where('tenant_id', $tenantId)
            ->where('scenario_type', PlanningScenario::Baseline)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['scenario_type' => [__('planning_errors.scenario.baseline_exists')]]);
        }
    }

    private function assertSpan(string $start, string $end): void
    {
        if (PlanningPeriods::monthStart($start)->diffInMonths(PlanningPeriods::monthStart($end)) >= self::MaxMonths) {
            throw ValidationException::withMessages(['horizon_end' => [__('planning_errors.scenario.horizon_too_long', ['months' => self::MaxMonths])]]);
        }
    }

    private function factor(mixed $percent): string
    {
        return bcadd('1', bcdiv((string) ($percent ?? '0'), '100', 8), 8);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(['name', 'description', 'scenario_type', 'horizon_start', 'horizon_end']));
    }
}
