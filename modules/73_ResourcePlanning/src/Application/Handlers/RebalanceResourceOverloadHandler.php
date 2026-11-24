<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\ResourcePlanning\Application\Commands\RebalanceResourceOverloadCommand;
use Modules\ResourcePlanning\Application\DTOs\AllocationOutcome;
use Modules\ResourcePlanning\Application\Services\AllocationPlanner;
use Modules\ResourcePlanning\Domain\Enums\RebalanceStrategy;
use Modules\ResourcePlanning\Domain\Exceptions\ResourceNotFoundException;
use Modules\ResourcePlanning\Domain\Exceptions\ResourcePlanningException;
use Modules\ResourcePlanning\Infrastructure\Models\ResourceAllocation;

/**
 * Relieves an overloaded employee by moving work in time (shift), to a colleague (reassign), by cutting an allocation in two
 * (split) or by changing its length or intensity (resize). A rebalance is never refused for overload: it is the cure.
 */
final class RebalanceResourceOverloadHandler
{
    public function __construct(private readonly AllocationPlanner $planner) {}

    public function handle(RebalanceResourceOverloadCommand $command): AllocationOutcome
    {
        $allocation = ResourceAllocation::query()->find($command->allocationId)
            ?? throw new ResourceNotFoundException('allocation_not_found', 'The allocation does not exist.');

        $employees = [$allocation->employee_id];
        $from = $allocation->start_date;
        $to = $allocation->end_date;

        /** @var list<ResourceAllocation> $written */
        $written = DB::transaction(fn (): array => match ($command->strategy) {
            RebalanceStrategy::Shift => [$this->shift($allocation, $command)],
            RebalanceStrategy::Reassign => [$this->reassign($allocation, $command)],
            RebalanceStrategy::Resize => [$this->resize($allocation, $command)],
            RebalanceStrategy::Split => $this->split($allocation, $command),
        });

        foreach ($written as $row) {
            $employees[] = $row->employee_id;
            $from = min($from, $row->start_date);
            $to = max($to, $row->end_date);
        }

        $this->planner->synchronise($employees, $from, $to);
        $moved = $written[count($written) - 1];

        return new AllocationOutcome(
            $written[0]->refresh(),
            array_map(static fn (ResourceAllocation $row): ResourceAllocation => $row->refresh(), $written),
            $this->planner->overloadOf($moved->employee_id, $moved->start_date, $moved->end_date),
        );
    }

    private function shift(ResourceAllocation $allocation, RebalanceResourceOverloadCommand $command): ResourceAllocation
    {
        $length = (int) $allocation->start_date->diffInDays($allocation->end_date);
        $start = $command->startDate?->startOfDay()
            ?? ($command->shiftDays !== null ? $allocation->start_date->addDays($command->shiftDays) : throw new ResourcePlanningException('invalid_period', 'A shift needs a start date or a number of days.'));

        return $this->apply($allocation, $command->employeeId ?? $allocation->employee_id, $start, $start->addDays($length), (float) $allocation->allocated_hours_per_day);
    }

    private function reassign(ResourceAllocation $allocation, RebalanceResourceOverloadCommand $command): ResourceAllocation
    {
        if ($command->employeeId === null) {
            throw new ResourcePlanningException('employee_not_found', 'A reassignment needs the new employee.');
        }

        return $this->apply($allocation, $command->employeeId, $allocation->start_date, $allocation->end_date, (float) $allocation->allocated_hours_per_day);
    }

    private function resize(ResourceAllocation $allocation, RebalanceResourceOverloadCommand $command): ResourceAllocation
    {
        return $this->apply(
            $allocation,
            $allocation->employee_id,
            $command->startDate?->startOfDay() ?? $allocation->start_date,
            $command->endDate?->startOfDay() ?? $allocation->end_date,
            $command->hoursPerDay ?? (float) $allocation->allocated_hours_per_day,
        );
    }

    /**
     * The first part keeps the employee, the dates before the split date and the hours; the second part starts on the split
     * date and can go to a colleague, later in time (shift_days) or at another intensity.
     *
     * @return list<ResourceAllocation>
     */
    private function split(ResourceAllocation $allocation, RebalanceResourceOverloadCommand $command): array
    {
        $splitDate = $command->splitDate?->startOfDay();

        if ($splitDate === null || $splitDate <= $allocation->start_date || $splitDate > $allocation->end_date) {
            throw new ResourcePlanningException('invalid_period', 'The split date must fall after the start and within the allocation.', ['split_date' => $splitDate?->format('Y-m-d')]);
        }

        $second = $allocation->replicate(['is_overloaded', 'peak_load_percent', 'total_allocated_hours']);
        $shift = $command->shiftDays ?? 0;
        $secondEnd = $allocation->end_date;

        $first = $this->apply($allocation, $allocation->employee_id, $allocation->start_date, $splitDate->subDay(), (float) $allocation->allocated_hours_per_day);

        return [$first, $this->apply($second, $command->employeeId ?? $allocation->employee_id, $splitDate->addDays($shift), $secondEnd->addDays($shift), $command->hoursPerDay ?? (float) $allocation->allocated_hours_per_day)];
    }

    private function apply(ResourceAllocation $allocation, string $employeeId, CarbonImmutable $start, CarbonImmutable $end, float $hoursPerDay): ResourceAllocation
    {
        $this->planner->assertPeriod($start, $end);
        $profile = $this->planner->profile($employeeId, $start);
        $total = $this->planner->totalHours($profile, $start, $end, $hoursPerDay);

        $allocation->forceFill([
            'employee_id' => $employeeId,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'allocated_hours_per_day' => $hoursPerDay,
            'total_allocated_hours' => $total,
        ])->save();

        return $allocation;
    }
}
