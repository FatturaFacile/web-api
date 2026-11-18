<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Offboarding;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Exceptions\HumanResourcesException;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\ExitInterview;
use Modules\HumanResources\Models\OnboardingChecklist;
use Modules\HumanResources\Models\OnboardingTask;
use Modules\HumanResources\Models\OnboardingTemplate;

/**
 * Offboarding of an employee who leaves: a checklist (the onboarding checklist of kind offboarding) with the return of
 * the assets, the revocation of the accesses, the exit interview and the final settlement.
 */
final class OffboardingService
{
    public const string TaskAssetReturn = 'asset_return';

    public const string TaskAccessRevocation = 'access_revocation';

    public const string TaskExitInterview = 'exit_interview';

    public const string TaskFinalSettlement = 'final_settlement';

    public const string TaskCustom = 'custom';

    /** @var list<string> */
    public const array TaskCodes = [self::TaskAssetReturn, self::TaskAccessRevocation, self::TaskExitInterview, self::TaskFinalSettlement, self::TaskCustom];

    /**
     * Opens the offboarding of a terminated employee. The tasks come from the template (the given one, or the oldest
     * offboarding template of the company, or the built-in list). A task of code asset_return is repeated for each item of
     * the equipment assigned to the employee, and dropped when there is none.
     */
    public function startOffboarding(Employee $employee, ?OnboardingTemplate $template = null): OnboardingChecklist
    {
        if ($employee->employment_status !== 'terminated') {
            throw new HumanResourcesException(__('hr_offboarding.employee_not_leaving'));
        }

        return DB::transaction(function () use ($employee, $template): OnboardingChecklist {
            $open = OnboardingChecklist::query()
                ->where('employee_id', $employee->id)
                ->where('kind', OnboardingChecklist::KindOffboarding)
                ->whereNull('completed_at')
                ->exists();

            if ($open) {
                throw new HumanResourcesException(__('hr_offboarding.already_started'));
            }

            $template ??= OnboardingTemplate::query()->where('kind', OnboardingTemplate::KindOffboarding)->orderBy('created_at')->first();
            $reference = Carbon::parse($employee->termination_date ?? Carbon::today())->startOfDay();

            $checklist = OnboardingChecklist::create([
                'employee_id' => $employee->id,
                'onboarding_template_id' => $template?->id,
                'kind' => OnboardingChecklist::KindOffboarding,
                'reference_date' => $reference->format('Y-m-d'),
            ]);

            $order = 0;
            foreach ($this->taskDefinitions($employee, $template) as $definition) {
                OnboardingTask::create([
                    'onboarding_checklist_id' => $checklist->id,
                    'title' => $definition['title'],
                    'code' => $definition['code'],
                    'due_on' => $reference->copy()->addDays($definition['due_after_days'])->format('Y-m-d'),
                    'sort_order' => $order++,
                ]);
            }

            return $checklist->load('tasks');
        });
    }

    /**
     * Starts the offboarding as part of a termination: an offboarding still open from an earlier departure is kept as it
     * is, so that the termination itself is never refused because of it.
     */
    public function startForTermination(Employee $employee, ?OnboardingTemplate $template = null): OnboardingChecklist
    {
        $open = OnboardingChecklist::query()
            ->where('employee_id', $employee->id)
            ->where('kind', OnboardingChecklist::KindOffboarding)
            ->whereNull('completed_at')
            ->first();

        return $open ?? $this->startOffboarding($employee, $template);
    }

    /**
     * Marks the task done; the last task closes the checklist.
     */
    public function completeTask(OnboardingTask $task, User $actor): OnboardingTask
    {
        return DB::transaction(function () use ($task, $actor): OnboardingTask {
            $checklist = OnboardingChecklist::query()->lockForUpdate()->findOrFail($task->onboarding_checklist_id);

            if ($checklist->kind !== OnboardingChecklist::KindOffboarding) {
                throw new HumanResourcesException(__('hr_offboarding.not_offboarding_task'));
            }

            $this->markDone($task, $actor->id);

            return $task->refresh();
        });
    }

    /**
     * Records the exit interview of a terminated employee (one per employee: a second record replaces the first) and
     * closes the exit interview task of the open offboarding.
     *
     * @param  array{conducted_on: string, reason_code: string, interviewer_employee_id?: string|null, would_recommend?: bool|null, eligible_for_rehire?: bool|null, satisfaction_rating?: int|null, feedback?: string|null}  $data
     */
    public function recordExitInterview(Employee $employee, array $data, ?User $actor = null): ExitInterview
    {
        if ($employee->employment_status !== 'terminated') {
            throw new HumanResourcesException(__('hr_offboarding.employee_not_leaving'));
        }

        return DB::transaction(function () use ($employee, $data, $actor): ExitInterview {
            $checklist = OnboardingChecklist::query()
                ->where('employee_id', $employee->id)
                ->where('kind', OnboardingChecklist::KindOffboarding)
                ->orderByDesc('created_at')
                ->first();

            $interview = ExitInterview::query()->where('employee_id', $employee->id)->first() ?? new ExitInterview(['employee_id' => $employee->id]);
            $interview->fill([...$data, 'onboarding_checklist_id' => $checklist?->id]);
            $interview->save();

            if ($checklist !== null) {
                $task = OnboardingTask::query()
                    ->where('onboarding_checklist_id', $checklist->id)
                    ->where('code', self::TaskExitInterview)
                    ->whereNull('completed_at')
                    ->first();

                if ($task !== null) {
                    $this->markDone($task, $actor?->id);
                }
            }

            return $interview->refresh();
        });
    }

    private function markDone(OnboardingTask $task, ?string $actorId): void
    {
        $task->update(['completed_at' => $task->completed_at ?? Carbon::now(), 'completed_by' => $task->completed_by ?? $actorId]);

        $open = OnboardingTask::query()->where('onboarding_checklist_id', $task->onboarding_checklist_id)->whereNull('completed_at')->exists();

        if (! $open) {
            OnboardingChecklist::query()->whereKey($task->onboarding_checklist_id)->update(['completed_at' => Carbon::now()]);
        }
    }

    /**
     * @return list<array{title: string, code: string, due_after_days: int}>
     */
    private function taskDefinitions(Employee $employee, ?OnboardingTemplate $template): array
    {
        $source = $template !== null ? $template->tasks : $this->defaultTasks();
        $assets = $this->assetNames($employee);
        $definitions = [];

        foreach ($source as $task) {
            $code = in_array($task['code'] ?? null, self::TaskCodes, true) ? (string) $task['code'] : self::TaskCustom;
            $due = (int) ($task['due_after_days'] ?? 0);

            if ($code !== self::TaskAssetReturn) {
                $definitions[] = ['title' => (string) $task['title'], 'code' => $code, 'due_after_days' => $due];

                continue;
            }

            foreach ($assets as $asset) {
                $definitions[] = ['title' => __('hr_offboarding.task_asset_return', ['asset' => $asset]), 'code' => $code, 'due_after_days' => $due];
            }
        }

        return $definitions;
    }

    /**
     * @return list<array{title: string, code: string, due_after_days: int}>
     */
    private function defaultTasks(): array
    {
        return [
            ['title' => __('hr_offboarding.task_asset_return', ['asset' => '']), 'code' => self::TaskAssetReturn, 'due_after_days' => 0],
            ['title' => __('hr_offboarding.task_access_revocation'), 'code' => self::TaskAccessRevocation, 'due_after_days' => 0],
            ['title' => __('hr_offboarding.task_exit_interview'), 'code' => self::TaskExitInterview, 'due_after_days' => -3],
            ['title' => __('hr_offboarding.task_final_settlement'), 'code' => self::TaskFinalSettlement, 'due_after_days' => 5],
        ];
    }

    /**
     * The items of the equipment field: plain names, or records with a name, a description, a type or an asset tag.
     *
     * @return list<string>
     */
    private function assetNames(Employee $employee): array
    {
        $names = [];

        foreach ((array) ($employee->equipment_assigned ?? []) as $item) {
            $name = is_array($item)
                ? (string) ($item['name'] ?? $item['description'] ?? $item['type'] ?? $item['asset_tag'] ?? '')
                : (string) $item;

            if (trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        return $names;
    }
}
