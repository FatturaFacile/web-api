<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\ResourcePlanning\Application\DTOs\AllocationOutcome;
use Modules\ResourcePlanning\Domain\Contracts\OpportunityPort;
use Modules\ResourcePlanning\Domain\Contracts\ProjectPort;
use Modules\ResourcePlanning\Domain\Contracts\ResourceDirectoryPort;
use Modules\ResourcePlanning\Domain\Enums\BookingType;
use Modules\ResourcePlanning\Infrastructure\Models\ResourceAllocation;

/**
 * Lists allocations and turns them, and the outcome of a booking command, into the arrays of the API (the names of the
 * employee, project, WBS node and opportunity come from the neighbouring modules through their ports).
 */
final class AllocationPresenter
{
    public function __construct(
        private readonly ResourceDirectoryPort $directory,
        private readonly ProjectPort $projects,
        private readonly OpportunityPort $opportunities,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listing(?CarbonImmutable $from, ?CarbonImmutable $to, ?string $employeeId, ?string $projectId, ?BookingType $type): array
    {
        $allocations = ResourceAllocation::query()
            ->when($from !== null, static fn (Builder $query) => $query->whereDate('end_date', '>=', $from?->format('Y-m-d')))
            ->when($to !== null, static fn (Builder $query) => $query->whereDate('start_date', '<=', $to?->format('Y-m-d')))
            ->when($employeeId !== null, static fn (Builder $query) => $query->where('employee_id', $employeeId))
            ->when($projectId !== null, static fn (Builder $query) => $query->where('project_id', $projectId))
            ->when($type !== null, static fn (Builder $query) => $query->where('booking_type', $type?->value))
            ->orderBy('start_date')
            ->orderBy('employee_id')
            ->get();

        return $this->present($allocations->all());
    }

    /**
     * @param  list<ResourceAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    public function present(array $allocations): array
    {
        $employees = $this->directory->names(array_values(array_unique(array_map(static fn (ResourceAllocation $allocation): string => $allocation->employee_id, $allocations))));
        $projects = $this->projects->projectNames($this->ids($allocations, 'project_id'));
        $nodes = $this->projects->wbsTitles($this->ids($allocations, 'wbs_node_id'));
        $opportunities = $this->opportunities->opportunityNames($this->ids($allocations, 'crm_opportunity_id'));

        return array_map(static fn (ResourceAllocation $allocation): array => [
            'id' => $allocation->id,
            'project_id' => $allocation->project_id,
            'project_name' => $allocation->project_id === null ? null : ($projects[$allocation->project_id] ?? null),
            'wbs_node_id' => $allocation->wbs_node_id,
            'wbs_node_title' => $allocation->wbs_node_id === null ? null : ($nodes[$allocation->wbs_node_id] ?? null),
            'employee_id' => $allocation->employee_id,
            'employee_name' => $employees[$allocation->employee_id] ?? null,
            'role_id' => $allocation->role_id,
            'booking_type' => $allocation->booking_type->value,
            'start_date' => $allocation->start_date->format('Y-m-d'),
            'end_date' => $allocation->end_date->format('Y-m-d'),
            'allocated_hours_per_day' => (float) $allocation->allocated_hours_per_day,
            'total_allocated_hours' => (float) $allocation->total_allocated_hours,
            'is_billable' => $allocation->is_billable,
            'crm_opportunity_id' => $allocation->crm_opportunity_id,
            'crm_opportunity_name' => $allocation->crm_opportunity_id === null ? null : ($opportunities[$allocation->crm_opportunity_id] ?? null),
            'is_overloaded' => $allocation->is_overloaded,
            'peak_load_percent' => (float) $allocation->peak_load_percent,
            'notes' => $allocation->notes,
        ], $allocations);
    }

    /**
     * @return array<string, mixed>
     */
    public function outcome(AllocationOutcome $outcome): array
    {
        $presented = $this->present($outcome->allocations);
        $primary = $this->present([$outcome->allocation])[0];

        return [
            'allocation' => $primary,
            'allocations' => $presented,
            'overload' => $outcome->overload->toArray(),
            'warnings' => $outcome->warnings,
        ];
    }

    /**
     * @param  list<ResourceAllocation>  $allocations
     * @return list<string>
     */
    private function ids(array $allocations, string $attribute): array
    {
        $ids = [];

        foreach ($allocations as $allocation) {
            $value = $allocation->getAttribute($attribute);

            if (is_string($value)) {
                $ids[$value] = $value;
            }
        }

        return array_values($ids);
    }
}
