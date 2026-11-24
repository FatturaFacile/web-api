<?php

declare(strict_types=1);

namespace Modules\Planning\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Planning\Models\PlanningCapacityConstraint;
use Modules\Planning\Models\PlanningItemResourceRate;
use Modules\Planning\Models\PlanningPlanLine;
use Modules\Planning\Models\PlanningResource;
use Modules\Planning\Models\PlanningScenario;

/**
 * Capacity resources, the load an item puts on them and the finite capacity check of a scenario: the supply plan loads
 * every resource (quantity times the load per unit) and a month is overloaded when the load exceeds the capacity, the
 * capacity constraint of the scenario if there is one, else the default capacity of the resource.
 */
final class CapacityService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createResource(string $tenantId, array $data): PlanningResource
    {
        $this->assertCodeFree($tenantId, (string) $data['code'], null);

        return PlanningResource::create([...$this->resourceFields($data), 'tenant_id' => $tenantId])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateResource(PlanningResource $resource, array $data): PlanningResource
    {
        if (isset($data['code'])) {
            $this->assertCodeFree($resource->tenant_id, (string) $data['code'], $resource->id);
        }

        $resource->update($this->resourceFields($data));

        return $resource->refresh();
    }

    public function deleteResource(PlanningResource $resource): void
    {
        $inUse = PlanningCapacityConstraint::query()
            ->where('resource_id', $resource->id)
            ->whereIn('scenario_id', PlanningScenario::query()->where('tenant_id', $resource->tenant_id)->where('status', '!=', PlanningScenario::Draft)->select('id'))
            ->exists();

        if ($inUse) {
            throw ValidationException::withMessages(['id' => [__('planning_errors.resource.in_use')]]);
        }

        DB::transaction(function () use ($resource): void {
            $resource->rates()->get()->each->delete();
            PlanningCapacityConstraint::query()->where('resource_id', $resource->id)->get()->each->delete();
            $resource->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveRate(string $tenantId, PlanningResource $resource, array $data, ?PlanningItemResourceRate $rate = null): PlanningItemResourceRate
    {
        $data['item_code'] ??= $rate?->item_code;
        $duplicate = PlanningItemResourceRate::query()
            ->where('resource_id', $resource->id)
            ->where('item_code', $data['item_code'])
            ->when($rate !== null, static fn ($query) => $query->whereKeyNot($rate->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['item_code' => [__('planning_errors.resource.rate_exists')]]);
        }

        if ($rate === null) {
            return PlanningItemResourceRate::create([
                'tenant_id' => $tenantId,
                'resource_id' => $resource->id,
                'item_code' => $data['item_code'],
                'load_per_unit' => $data['load_per_unit'],
            ])->refresh();
        }

        $rate->update(array_intersect_key($data, array_flip(['item_code', 'load_per_unit'])));

        return $rate->refresh();
    }

    /**
     * Sets the capacity of a resource in a month of a draft scenario (one constraint per resource and month).
     *
     * @param  array<string, mixed>  $data
     */
    public function saveConstraint(PlanningScenario $scenario, array $data): PlanningCapacityConstraint
    {
        if (! $scenario->isDraft()) {
            throw ValidationException::withMessages(['status' => [__('planning_errors.scenario.not_draft')]]);
        }
        $period = PlanningPeriods::monthStart((string) $data['period']);

        if (! PlanningPeriods::covers($scenario, $period)) {
            throw ValidationException::withMessages(['period' => [__('planning_errors.line.outside_horizon')]]);
        }

        $constraint = PlanningCapacityConstraint::query()
            ->where('scenario_id', $scenario->id)
            ->where('resource_id', $data['resource_id'])
            ->whereDate('period', $period->toDateString())
            ->first();

        $values = ['available_capacity' => $data['available_capacity'], 'notes' => $data['notes'] ?? null];

        if ($constraint === null) {
            return PlanningCapacityConstraint::create([
                'tenant_id' => $scenario->tenant_id,
                'scenario_id' => $scenario->id,
                'resource_id' => $data['resource_id'],
                'period' => $period->toDateString(),
                ...$values,
            ])->refresh();
        }

        $constraint->update($values);

        return $constraint->refresh();
    }

    /**
     * The finite capacity check: load and capacity per resource and month, with the overloaded months.
     *
     * @return array{scenario_id: string, periods: list<string>, rows: list<array<string, mixed>>, summary: array{feasible: bool, overloaded_count: int, max_utilization_percent: string|null, resources: int}}
     */
    public function check(PlanningScenario $scenario): array
    {
        $periods = PlanningPeriods::months($scenario);
        $rates = PlanningItemResourceRate::query()->where('tenant_id', $scenario->tenant_id)->get()->groupBy('resource_id');
        $constraints = PlanningCapacityConstraint::query()->where('scenario_id', $scenario->id)->get()->groupBy('resource_id');
        $resourceIds = $rates->keys()->merge($constraints->keys())->unique()->all();
        $resources = PlanningResource::query()->where('tenant_id', $scenario->tenant_id)->whereIn('id', $resourceIds)->orderBy('code')->get();

        $supply = [];
        foreach (PlanningPlanLine::query()->where('scenario_id', $scenario->id)->where('line_type', PlanningPlanLine::Supply)->get() as $line) {
            $supply[$line->item_code][$line->period->toDateString()] = bcadd($supply[$line->item_code][$line->period->toDateString()] ?? '0', (string) $line->quantity, 3);
        }

        $rows = [];
        $overloaded = 0;
        $maxUtilization = null;

        foreach ($resources as $resource) {
            $constraintByPeriod = ($constraints->get($resource->id) ?? collect())->keyBy(static fn (PlanningCapacityConstraint $constraint): string => $constraint->period->toDateString());

            foreach ($periods as $period) {
                $load = '0';
                foreach ($rates->get($resource->id) ?? [] as $rate) {
                    $load = bcadd($load, bcmul($supply[$rate->item_code][$period] ?? '0', (string) $rate->load_per_unit, 8), 8);
                }

                $constraint = $constraintByPeriod->get($period);
                $available = (string) ($constraint->available_capacity ?? $resource->default_capacity);
                $isOverloaded = bccomp($load, $available, 3) > 0;
                $utilization = bccomp($available, '0', 3) > 0 ? bcround(bcmul(bcdiv($load, $available, 8), '100', 8), 1) : null;

                $overloaded += $isOverloaded ? 1 : 0;
                if ($utilization !== null && ($maxUtilization === null || bccomp($utilization, $maxUtilization, 1) > 0)) {
                    $maxUtilization = $utilization;
                }

                $rows[] = [
                    'resource_id' => $resource->id,
                    'resource_code' => $resource->code,
                    'resource_name' => $resource->name,
                    'unit' => $resource->unit,
                    'period' => $period,
                    'load' => bcround($load, 3),
                    'available' => bcadd($available, '0', 3),
                    'source' => $constraint !== null ? 'constraint' : 'default',
                    'utilization_percent' => $utilization,
                    'overload' => $isOverloaded ? bcround(bcsub($load, $available, 8), 3) : '0.000',
                    'is_overloaded' => $isOverloaded,
                ];
            }
        }

        return [
            'scenario_id' => $scenario->id,
            'periods' => $periods,
            'rows' => $rows,
            'summary' => ['feasible' => $overloaded === 0, 'overloaded_count' => $overloaded, 'max_utilization_percent' => $maxUtilization, 'resources' => $resources->count()],
        ];
    }

    private function assertCodeFree(string $tenantId, string $code, ?string $ignoreId): void
    {
        $exists = PlanningResource::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['code' => [__('planning_errors.resource.code_taken', ['code' => $code])]]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resourceFields(array $data): array
    {
        return array_intersect_key($data, array_flip(['code', 'name', 'work_center_id', 'unit', 'default_capacity', 'is_active']));
    }
}
