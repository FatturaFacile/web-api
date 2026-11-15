<?php

declare(strict_types=1);

namespace Modules\Planning\Services;

use Modules\Planning\Models\PlanningPublication;
use Modules\Planning\Models\PlanningScenario;

/**
 * The planner's overview: scenarios per status, the current publication, the biggest supply gaps and the capacity load
 * of the selected scenario (the one asked for, else the current publication, else the baseline, else the latest).
 */
final readonly class PlanningDashboardService
{
    public function __construct(private BalanceService $balance, private CapacityService $capacity) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, ?string $scenarioId): array
    {
        $scenarios = PlanningScenario::query()->where('tenant_id', $tenantId)->orderByDesc('created_at')->get();
        $publication = PlanningPublication::query()->where('tenant_id', $tenantId)->where('is_current', true)->first();

        $selected = $scenarioId !== null
            ? $scenarios->firstWhere('id', $scenarioId)
            : ($scenarios->firstWhere('id', $publication?->scenario_id)
                ?? $scenarios->firstWhere('scenario_type', PlanningScenario::Baseline)
                ?? $scenarios->first());

        $base = [
            'scenarios' => [
                'total' => $scenarios->count(),
                'by_status' => collect(PlanningScenario::Statuses)->mapWithKeys(static fn (string $status): array => [$status => $scenarios->where('status', $status)->count()])->all(),
            ],
            'pending_approval' => $scenarios->where('status', PlanningScenario::Review)->map(static fn (PlanningScenario $scenario): array => ['id' => $scenario->id, 'name' => $scenario->name, 'submitted_at' => $scenario->submitted_at?->toIso8601String()])->values()->all(),
            'current_publication' => $publication === null ? null : ['version' => $publication->version, 'scenario_id' => $publication->scenario_id, 'published_at' => $publication->published_at->toIso8601String()],
            'selected_scenario' => null,
            'totals' => null,
            'top_gaps' => [],
            'capacity' => ['summary' => ['feasible' => true, 'overloaded_count' => 0, 'max_utilization_percent' => null, 'resources' => 0], 'resources' => []],
        ];

        if ($selected === null) {
            return $base;
        }

        $balance = $this->balance->balance($selected, true);
        $gaps = array_values(array_filter($balance['items'], static fn (array $item): bool => bccomp($item['max_shortage'], '0', 3) > 0));
        usort($gaps, static fn (array $left, array $right): int => bccomp($right['max_shortage'], $left['max_shortage'], 3));

        $check = $this->capacity->check($selected);
        $resources = [];
        foreach ($check['rows'] as $row) {
            $resources[$row['resource_id']] ??= ['resource_id' => $row['resource_id'], 'resource_code' => $row['resource_code'], 'resource_name' => $row['resource_name'], 'unit' => $row['unit'], 'peak_utilization_percent' => null, 'overloaded_periods' => 0, 'periods' => []];
            $resources[$row['resource_id']]['periods'][] = ['period' => $row['period'], 'load' => $row['load'], 'available' => $row['available'], 'utilization_percent' => $row['utilization_percent'], 'is_overloaded' => $row['is_overloaded']];
            $resources[$row['resource_id']]['overloaded_periods'] += $row['is_overloaded'] ? 1 : 0;
            $peak = $resources[$row['resource_id']]['peak_utilization_percent'];

            if ($row['utilization_percent'] !== null && ($peak === null || bccomp($row['utilization_percent'], $peak, 1) > 0)) {
                $resources[$row['resource_id']]['peak_utilization_percent'] = $row['utilization_percent'];
            }
        }

        return [
            ...$base,
            'selected_scenario' => ['id' => $selected->id, 'name' => $selected->name, 'status' => $selected->status, 'scenario_type' => $selected->scenario_type],
            'totals' => $balance['totals'],
            'top_gaps' => array_map(static fn (array $item): array => ['item_code' => $item['item_code'], 'item_name' => $item['item_name'], 'total_gap' => $item['total_gap'], 'ending_gap' => $item['ending_gap'], 'max_shortage' => $item['max_shortage'], 'shortage_periods' => $item['shortage_periods']], array_slice($gaps, 0, 10)),
            'capacity' => ['summary' => $check['summary'], 'resources' => array_values($resources)],
        ];
    }
}
