<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectResource;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Models\ProjectTaskAssignment;
use Modules\Projects\Models\ResourceCapacityProfile;
use Modules\Projects\Support\Money;

/**
 * Weekly load of people against their capacity, across every open project of the company.
 *
 * The hours of a task assignment are spread evenly over the working days (Monday to Friday) between the planned start
 * and end of the task, then summed by ISO week. The capacity of a person is the weekly capacity of their project
 * resource profile; without a profile the standard week of DefaultWeeklyCapacity hours is assumed and reported as such.
 * A week is over-allocated when the planned load exceeds the capacity.
 */
final class ResourceCapacityService
{
    public const string DefaultWeeklyCapacity = '40.00';

    /** Longest window the report computes in one call. */
    public const int MaxWeeks = 26;

    /**
     * @return list<array{user_id: string, user_name: string|null, capacity_source: string, weeks: list<array{week: string, week_start: string, capacity_hours: string, allocated_hours: string, available_hours: string, utilisation_percentage: string|null, is_over_allocated: bool}>, over_allocated_weeks: int}>
     */
    public function weeklyLoad(CarbonImmutable $from, CarbonImmutable $to, ?string $userId = null): array
    {
        $from = $from->startOfWeek();
        $to = $to->endOfWeek();

        $assignments = ProjectTaskAssignment::query()
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->whereHas('task', fn ($query) => $query
                ->whereDate('planned_start_date', '<=', $to->toDateString())
                ->whereDate('planned_end_date', '>=', $from->toDateString())
                ->where('status', '!=', 'completed')
                ->whereHas('project', fn ($projects) => $projects->whereNotIn('status', [...Project::FinalStatuses, 'completed'])))
            ->with('task:id,project_id,planned_start_date,planned_end_date')
            ->get();

        $load = [];

        foreach ($assignments as $assignment) {
            $task = $assignment->task;

            if (! $task instanceof ProjectTask) {
                continue;
            }

            $workingDays = $this->workingDays(CarbonImmutable::parse($task->planned_start_date), CarbonImmutable::parse($task->planned_end_date));

            if ($workingDays === []) {
                continue;
            }

            $perDay = Money::div($assignment->allocated_hours, count($workingDays)) ?? '0';

            foreach ($workingDays as $day) {
                if ($day->lessThan($from) || $day->greaterThan($to)) {
                    continue;
                }

                $week = $day->format('o-\WW');
                $load[$assignment->user_id][$week] = Money::add($load[$assignment->user_id][$week] ?? '0', $perDay);
            }
        }

        $userIds = $userId !== null ? [$userId] : array_keys($load);
        $capacities = $this->capacities($userIds);
        $names = User::query()->whereIn('id', $userIds)->pluck('name', 'id');
        $report = [];

        foreach ($userIds as $id) {
            $capacity = $capacities[$id] ?? null;
            $weeklyCapacity = $capacity ?? self::DefaultWeeklyCapacity;
            $weeks = [];
            $overAllocated = 0;

            foreach (CarbonPeriod::create($from, '1 week', $to) as $weekStart) {
                $week = $weekStart->format('o-\WW');
                $allocated = Money::amount($load[$id][$week] ?? '0');
                $isOver = Money::compare($allocated, $weeklyCapacity) > 0;
                $overAllocated += $isOver ? 1 : 0;

                $weeks[] = [
                    'week' => $week,
                    'week_start' => $weekStart->toDateString(),
                    'capacity_hours' => Money::amount($weeklyCapacity),
                    'allocated_hours' => $allocated,
                    'available_hours' => Money::amount(Money::sub($weeklyCapacity, $allocated)),
                    'utilisation_percentage' => Money::percent(Money::div($allocated, $weeklyCapacity)),
                    'is_over_allocated' => $isOver,
                ];
            }

            $report[] = [
                'user_id' => (string) $id,
                'user_name' => $names[$id] ?? null,
                'capacity_source' => $capacity !== null ? 'resource_profile' : 'default',
                'weeks' => $weeks,
                'over_allocated_weeks' => $overAllocated,
            ];
        }

        return $report;
    }

    /**
     * Creates or updates the project resource of a person: default rates and weekly capacity.
     *
     * @param  array{name?: string|null, internal_hourly_cost: string|int|float, billing_hourly_rate: string|int|float, weekly_capacity_hours?: string|int|float|null, is_active?: bool|null}  $attributes
     */
    public function upsertPersonResource(string $tenantId, User $user, array $attributes): ProjectResource
    {
        return DB::transaction(function () use ($tenantId, $user, $attributes): ProjectResource {
            $resource = ProjectResource::query()->where('user_id', $user->id)->where('resource_type', 'human')->lockForUpdate()->first()
                ?? new ProjectResource(['tenant_id' => $tenantId, 'user_id' => $user->id, 'resource_type' => 'human']);

            $resource->fill([
                'name' => $attributes['name'] ?? $resource->name ?? $user->name,
                'internal_hourly_cost' => Money::amount($attributes['internal_hourly_cost']),
                'billing_hourly_rate' => Money::amount($attributes['billing_hourly_rate']),
                'is_active' => $attributes['is_active'] ?? true,
            ])->save();

            if (isset($attributes['weekly_capacity_hours'])) {
                $profile = ResourceCapacityProfile::query()->where('resource_id', $resource->id)->first()
                    ?? new ResourceCapacityProfile(['tenant_id' => $tenantId, 'resource_id' => $resource->id]);
                $profile->fill(['weekly_capacity_hours' => Money::amount($attributes['weekly_capacity_hours'])])->save();
            }

            return $resource->load('capacityProfile');
        });
    }

    /**
     * Weekly capacity of each person that has an active resource with a capacity profile.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    private function capacities(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $capacities = [];

        ProjectResource::query()
            ->whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->with('capacityProfile')
            ->get()
            ->each(function (ProjectResource $resource) use (&$capacities): void {
                if ($resource->capacityProfile !== null && $resource->user_id !== null) {
                    $capacities[$resource->user_id] = (string) $resource->capacityProfile->weekly_capacity_hours;
                }
            });

        return $capacities;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function workingDays(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            if (! $day->isWeekend()) {
                $days[] = $day;
            }
        }

        return $days;
    }
}
