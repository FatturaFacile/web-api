<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Goals;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Exceptions\HrRecordStateException;
use Modules\HumanResources\Exceptions\HumanResourcesException;
use Modules\HumanResources\Models\Goal;
use Modules\HumanResources\Models\GoalCheckIn;
use Modules\HumanResources\Models\GoalCycle;
use Modules\HumanResources\Models\GoalKeyResult;

/**
 * Goals and OKR: cycles, cascading goals, key results and check-ins. The progress of a goal comes from its key results,
 * or from its child goals, or from the last check-in when it has neither; it rolls up to the parent goals.
 */
final class GoalService
{
    /** Rank of the levels: a goal cascades from a goal of a higher level. */
    private const array LevelRank = [Goal::LevelCompany => 0, Goal::LevelTeam => 1, Goal::LevelEmployee => 2];

    /**
     * @param  array{name: string, starts_on: string, ends_on: string, status?: string}  $data
     */
    public function createCycle(array $data): GoalCycle
    {
        return GoalCycle::create([...$data, 'status' => $data['status'] ?? GoalCycle::StatusDraft]);
    }

    /**
     * @param  array{name?: string, starts_on?: string, ends_on?: string, status?: string}  $data
     */
    public function updateCycle(GoalCycle $cycle, array $data): GoalCycle
    {
        $this->assertCycleOpen($cycle);

        $start = Carbon::parse($data['starts_on'] ?? $cycle->starts_on);
        $end = Carbon::parse($data['ends_on'] ?? $cycle->ends_on);

        if ($end->lt($start)) {
            throw new HumanResourcesException(__('hr_goals.cycle_dates_invalid'));
        }

        $cycle->update($data);

        return $cycle;
    }

    /**
     * Closes the cycle: its goals are no longer changed. A finished goal is marked completed, a draft is cancelled.
     */
    public function closeCycle(GoalCycle $cycle): GoalCycle
    {
        return DB::transaction(function () use ($cycle): GoalCycle {
            $locked = GoalCycle::query()->lockForUpdate()->findOrFail($cycle->id);
            $this->assertCycleOpen($locked);

            Goal::query()->where('goal_cycle_id', $locked->id)->where('status', Goal::StatusDraft)->update(['status' => Goal::StatusCancelled]);
            Goal::query()->where('goal_cycle_id', $locked->id)->where('status', Goal::StatusActive)->where('progress', '>=', 100)->update(['status' => Goal::StatusCompleted]);

            $locked->update(['status' => GoalCycle::StatusClosed, 'closed_at' => Carbon::now()]);

            return $locked;
        });
    }

    /**
     * @param  array{goal_cycle_id: string, parent_goal_id?: string|null, level: string, employee_id?: string|null, team_id?: string|null, title: string, description?: string|null, weight?: float|int|string|null, due_on?: string|null, status?: string, key_results?: list<array{metric: string, unit?: string|null, start_value?: float|int|string|null, target_value: float|int|string, current_value?: float|int|string|null}>}  $data
     */
    public function createGoal(array $data): Goal
    {
        return DB::transaction(function () use ($data): Goal {
            $cycle = GoalCycle::query()->findOrFail($data['goal_cycle_id']);
            $this->assertCycleOpen($cycle);
            $this->assertShape($data['level'], $data['employee_id'] ?? null, $data['team_id'] ?? null);
            $this->assertParent($cycle, $data['level'], $data['parent_goal_id'] ?? null);

            $goal = Goal::create([
                'goal_cycle_id' => $cycle->id,
                'parent_goal_id' => $data['parent_goal_id'] ?? null,
                'level' => $data['level'],
                'employee_id' => $data['level'] === Goal::LevelEmployee ? $data['employee_id'] : null,
                'team_id' => $data['level'] === Goal::LevelTeam ? $data['team_id'] : null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'weight' => $data['weight'] ?? 1,
                'due_on' => $data['due_on'] ?? null,
                'status' => $data['status'] ?? Goal::StatusActive,
            ]);

            foreach (array_values($data['key_results'] ?? []) as $index => $keyResult) {
                $this->makeKeyResult($goal, $keyResult, $index);
            }

            $this->recalculate($goal);

            return $goal->refresh();
        });
    }

    /**
     * @param  array{parent_goal_id?: string|null, title?: string, description?: string|null, weight?: float|int|string|null, due_on?: string|null, status?: string}  $data
     */
    public function updateGoal(Goal $goal, array $data): Goal
    {
        return DB::transaction(function () use ($goal, $data): Goal {
            $goal = Goal::query()->lockForUpdate()->findOrFail($goal->id);
            $this->assertCycleOpen($goal->cycle);

            if (array_key_exists('parent_goal_id', $data) && $data['parent_goal_id'] !== $goal->parent_goal_id) {
                $this->assertParent($goal->cycle, $goal->level, $data['parent_goal_id'], $goal);
            }

            $goal->update($data);
            $this->recalculate($goal);

            return $goal->refresh();
        });
    }

    public function deleteGoal(Goal $goal): void
    {
        DB::transaction(function () use ($goal): void {
            $this->assertCycleOpen($goal->cycle);
            $parent = $goal->parent;

            Goal::query()->where('parent_goal_id', $goal->id)->update(['parent_goal_id' => null]);
            GoalKeyResult::query()->where('goal_id', $goal->id)->delete();
            $goal->delete();

            if ($parent !== null) {
                $this->recalculate($parent);
            }
        });
    }

    /**
     * @param  array{metric: string, unit?: string|null, start_value?: float|int|string|null, target_value: float|int|string, current_value?: float|int|string|null}  $data
     */
    public function addKeyResult(Goal $goal, array $data): GoalKeyResult
    {
        return DB::transaction(function () use ($goal, $data): GoalKeyResult {
            $this->assertCycleOpen($goal->cycle);
            $order = (int) (GoalKeyResult::query()->where('goal_id', $goal->id)->max('sort_order') ?? -1) + 1;
            $keyResult = $this->makeKeyResult($goal, $data, $order);
            $this->recalculate($goal);

            return $keyResult->refresh();
        });
    }

    /**
     * @param  array{metric?: string, unit?: string|null, start_value?: float|int|string|null, target_value?: float|int|string, current_value?: float|int|string|null}  $data
     */
    public function updateKeyResult(GoalKeyResult $keyResult, array $data): GoalKeyResult
    {
        return DB::transaction(function () use ($keyResult, $data): GoalKeyResult {
            $goal = $keyResult->goal;
            $this->assertCycleOpen($goal->cycle);

            $keyResult->fill($data);
            $keyResult->progress = $this->keyResultProgress($keyResult);
            $keyResult->save();
            $this->recalculate($goal);

            return $keyResult->refresh();
        });
    }

    public function deleteKeyResult(GoalKeyResult $keyResult): void
    {
        DB::transaction(function () use ($keyResult): void {
            $goal = $keyResult->goal;
            $this->assertCycleOpen($goal->cycle);
            $keyResult->delete();
            $this->recalculate($goal);
        });
    }

    /**
     * Records an update on the goal: the new value of a key result, or the progress of a goal that has no key results.
     *
     * @param  array{key_result_id?: string|null, value?: float|int|string|null, progress?: float|int|string|null, comment?: string|null}  $data
     */
    public function checkIn(Goal $goal, array $data, ?User $actor = null): GoalCheckIn
    {
        return DB::transaction(function () use ($goal, $data, $actor): GoalCheckIn {
            $goal = Goal::query()->lockForUpdate()->findOrFail($goal->id);
            $this->assertCycleOpen($goal->cycle);

            if (in_array($goal->status, [Goal::StatusCancelled, Goal::StatusCompleted], true)) {
                throw new HrRecordStateException(__('hr_goals.goal_not_open'));
            }

            $keyResultId = $data['key_result_id'] ?? null;
            $value = null;

            if ($keyResultId !== null) {
                $keyResult = GoalKeyResult::query()->where('goal_id', $goal->id)->findOrFail($keyResultId);
                $value = $data['value'] ?? null;

                if ($value === null) {
                    throw new HumanResourcesException(__('hr_goals.value_required'));
                }

                $keyResult->current_value = $value;
                $keyResult->progress = $this->keyResultProgress($keyResult);
                $keyResult->save();
            } else {
                if (GoalKeyResult::query()->where('goal_id', $goal->id)->exists() || Goal::query()->where('parent_goal_id', $goal->id)->exists()) {
                    throw new HumanResourcesException(__('hr_goals.progress_is_derived'));
                }

                if (! isset($data['progress'])) {
                    throw new HumanResourcesException(__('hr_goals.progress_required'));
                }

                $goal->progress = $this->clamp((float) $data['progress']);
                $goal->save();
            }

            $this->recalculate($goal);
            $goal->refresh();

            return GoalCheckIn::create([
                'goal_id' => $goal->id,
                'key_result_id' => $keyResultId,
                'value' => $value,
                'progress' => $goal->progress,
                'comment' => $data['comment'] ?? null,
                'checked_in_by' => $actor?->id,
                'checked_in_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Weighted average progress (0-100) of the active and completed employee goals of a cycle, or null without goals.
     * It is the figure a performance review can take as its goals score.
     */
    public function employeeProgress(string $employeeId, ?string $goalCycleId = null): ?float
    {
        $goals = Goal::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', [Goal::StatusActive, Goal::StatusCompleted])
            ->when($goalCycleId !== null, static fn ($query) => $query->where('goal_cycle_id', $goalCycleId))
            ->get(['weight', 'progress']);

        $weight = $goals->sum(static fn (Goal $goal): float => (float) $goal->weight);

        if ($goals->isEmpty() || $weight <= 0.0) {
            return null;
        }

        return round($goals->sum(static fn (Goal $goal): float => (float) $goal->progress * (float) $goal->weight) / $weight, 2);
    }

    /**
     * Recomputes the progress of the goal and of its ancestors.
     */
    private function recalculate(Goal $goal): void
    {
        $visited = [];
        $current = $goal;

        while ($current !== null && ! in_array($current->id, $visited, true)) {
            $visited[] = $current->id;
            $keyResults = GoalKeyResult::query()->where('goal_id', $current->id)->get(['progress']);

            if ($keyResults->isNotEmpty()) {
                $current->progress = round($keyResults->avg(static fn (GoalKeyResult $keyResult): float => (float) $keyResult->progress), 2);
            } else {
                $children = Goal::query()->where('parent_goal_id', $current->id)->whereIn('status', [Goal::StatusDraft, Goal::StatusActive, Goal::StatusCompleted])->get(['weight', 'progress']);
                $weight = $children->sum(static fn (Goal $child): float => (float) $child->weight);

                if ($children->isNotEmpty() && $weight > 0.0) {
                    $current->progress = round($children->sum(static fn (Goal $child): float => (float) $child->progress * (float) $child->weight) / $weight, 2);
                }
            }

            $current->save();
            $current = $current->parent_goal_id === null ? null : Goal::query()->find($current->parent_goal_id);
        }
    }

    /**
     * @param  array{metric: string, unit?: string|null, start_value?: float|int|string|null, target_value: float|int|string, current_value?: float|int|string|null}  $data
     */
    private function makeKeyResult(Goal $goal, array $data, int $order): GoalKeyResult
    {
        $keyResult = new GoalKeyResult([
            'goal_id' => $goal->id,
            'metric' => $data['metric'],
            'unit' => $data['unit'] ?? null,
            'start_value' => $data['start_value'] ?? 0,
            'target_value' => $data['target_value'],
            'current_value' => $data['current_value'] ?? ($data['start_value'] ?? 0),
            'sort_order' => $order,
        ]);
        $keyResult->progress = $this->keyResultProgress($keyResult);
        $keyResult->save();

        return $keyResult;
    }

    private function keyResultProgress(GoalKeyResult $keyResult): float
    {
        $start = (float) $keyResult->start_value;
        $target = (float) $keyResult->target_value;
        $current = (float) $keyResult->current_value;

        if ($target === $start) {
            return $current >= $target ? 100.0 : 0.0;
        }

        return round($this->clamp(($current - $start) / ($target - $start) * 100), 2);
    }

    private function clamp(float $progress): float
    {
        return max(0.0, min(100.0, $progress));
    }

    private function assertCycleOpen(GoalCycle $cycle): void
    {
        if ($cycle->status === GoalCycle::StatusClosed) {
            throw new HrRecordStateException(__('hr_goals.cycle_closed'));
        }
    }

    private function assertShape(string $level, ?string $employeeId, ?string $teamId): void
    {
        if ($level === Goal::LevelEmployee && $employeeId === null) {
            throw new HumanResourcesException(__('hr_goals.employee_required'));
        }

        if ($level === Goal::LevelTeam && $teamId === null) {
            throw new HumanResourcesException(__('hr_goals.team_required'));
        }
    }

    /**
     * The parent belongs to the same cycle, sits at a higher level and does not descend from the goal itself.
     */
    private function assertParent(GoalCycle $cycle, string $level, ?string $parentId, ?Goal $goal = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Goal::query()->findOrFail($parentId);

        if ($parent->goal_cycle_id !== $cycle->id) {
            throw new HumanResourcesException(__('hr_goals.parent_other_cycle'));
        }

        if (self::LevelRank[$parent->level] >= self::LevelRank[$level]) {
            throw new HumanResourcesException(__('hr_goals.parent_level_invalid'));
        }

        for ($ancestor = $parent; $goal !== null && $ancestor !== null; $ancestor = $ancestor->parent_goal_id === null ? null : Goal::query()->find($ancestor->parent_goal_id)) {
            if ($ancestor->id === $goal->id) {
                throw new HumanResourcesException(__('hr_goals.parent_loop'));
            }
        }
    }
}
