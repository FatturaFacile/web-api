<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Recruiting;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\HumanResources\Actions\HireEmployeeAction;
use Modules\HumanResources\DTOs\HireEmployeeDTO;
use Modules\HumanResources\Models\ApplicationStageEvent;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\JobApplication;
use Modules\HumanResources\Models\JobPosting;
use Modules\HumanResources\Models\OnboardingChecklist;
use Modules\HumanResources\Models\OnboardingTask;
use Modules\HumanResources\Models\OnboardingTemplate;
use Modules\HumanResources\Models\PipelineStage;
use Modules\HumanResources\Models\RecruitingSetting;

/**
 * The hiring pipeline, from the application to the employee. Every application that ends gets a date by which it is
 * erased; a consent of the candidate can only keep it longer.
 */
final class RecruitingService
{
    /** The stages of a company that has not defined its own. */
    private const array DefaultStages = [
        ['name' => 'Ricevuta', 'outcome' => null],
        ['name' => 'Colloquio', 'outcome' => null],
        ['name' => 'Offerta', 'outcome' => null],
        ['name' => 'Assunta', 'outcome' => PipelineStage::OutcomeHired],
        ['name' => 'Respinta', 'outcome' => PipelineStage::OutcomeRejected],
    ];

    public function __construct(
        private readonly HireEmployeeAction $hireEmployee,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return Collection<int, PipelineStage>
     */
    public function stages(): Collection
    {
        if (! PipelineStage::query()->exists()) {
            foreach (self::DefaultStages as $index => $stage) {
                PipelineStage::query()->create([...$stage, 'sort_order' => ($index + 1) * 10]);
            }
        }

        return PipelineStage::query()->orderBy('sort_order')->get();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function apply(JobPosting $posting, array $values, ?User $actor): JobApplication
    {
        if ($posting->status !== JobPosting::StatusOpen) {
            throw ValidationException::withMessages(['job_posting_id' => "L'annuncio non è aperto."]);
        }

        return DB::transaction(function () use ($posting, $values, $actor): JobApplication {
            $stage = $this->stages()->first();
            $application = JobApplication::query()->create([...$values, 'job_posting_id' => $posting->id, 'pipeline_stage_id' => $stage->id]);
            $this->recordStage($application, $stage, $actor);

            return $application;
        });
    }

    public function moveToStage(JobApplication $application, PipelineStage $stage, User $actor): JobApplication
    {
        $this->assertActive($application);

        if ($stage->outcome !== null) {
            throw ValidationException::withMessages(['pipeline_stage_id' => 'Una candidatura si chiude con le azioni di assunzione o rifiuto.']);
        }

        return DB::transaction(function () use ($application, $stage, $actor): JobApplication {
            $application = $this->lockActive($application);
            $application->update(['pipeline_stage_id' => $stage->id]);
            $this->recordStage($application, $stage, $actor);

            return $application;
        });
    }

    public function reject(JobApplication $application, User $actor): JobApplication
    {
        $this->assertActive($application);

        return DB::transaction(function () use ($application, $actor): JobApplication {
            $application = $this->lockActive($application);
            $this->close($application, JobApplication::StatusRejected, PipelineStage::OutcomeRejected, $actor);

            return $application;
        });
    }

    /**
     * The candidate's consent to stay in the pool. It never shortens the retention.
     */
    public function recordConsent(JobApplication $application, string $until): JobApplication
    {
        $retention = $application->retention_until?->format('Y-m-d');
        $application->update(['consent_until' => $until, 'retention_until' => $retention === null || $until > $retention ? $until : $retention]);

        return $application;
    }

    /**
     * Withdrawing the consent or a request for erasure ends the retention at once.
     */
    public function erase(JobApplication $application): void
    {
        $application->delete();
    }

    /**
     * Close a posting: applications still open have no reason to stay beyond the retention.
     */
    public function closePosting(JobPosting $posting): JobPosting
    {
        DB::transaction(function () use ($posting): void {
            $posting->update(['status' => JobPosting::StatusClosed]);
            $retention = $this->retentionDate();

            JobApplication::query()->where('job_posting_id', $posting->id)->where('status', JobApplication::StatusActive)->whereNull('retention_until')
                ->update(['retention_until' => $retention]);
        });

        return $posting;
    }

    /**
     * The hired candidate becomes an employee through the ordinary hire, and the onboarding starts from a template. The
     * application is then kept only until its retention ends: the employee record carries on from here.
     *
     * @param  array{base_annual_salary: float|int|string, hire_date: string, work_email?: string|null, department_id?: string|null, position_id?: string|null, employment_type?: string|null, contract_type?: string|null, weekly_hours?: float|int|string|null, probation_months?: int|null, onboarding_template_id?: string|null}  $terms
     */
    public function hire(JobApplication $application, array $terms, User $actor): Employee
    {
        $this->assertActive($application);

        return DB::transaction(function () use ($application, $terms, $actor): Employee {
            $application = $this->lockActive($application);
            $tenant = $this->tenantContext->getTenant();
            $posting = $application->posting;

            $employee = $this->hireEmployee->execute(new HireEmployeeDTO(
                tenantId: (string) $tenant->id,
                companyId: (string) $tenant->id,
                firstName: (string) $application->first_name,
                lastName: (string) $application->last_name,
                workEmail: (string) ($terms['work_email'] ?? $application->email),
                baseAnnualSalary: (float) $terms['base_annual_salary'],
                hireDate: $terms['hire_date'],
                departmentId: $terms['department_id'] ?? $posting?->department_id,
                positionId: $terms['position_id'] ?? $posting?->position_id,
                employmentType: $terms['employment_type'] ?? (string) ($posting->employment_type ?? 'full_time'),
                contractType: $terms['contract_type'] ?? 'permanent',
                weeklyHours: (float) ($terms['weekly_hours'] ?? 40),
                probationMonths: (int) ($terms['probation_months'] ?? 3),
                currency: (string) $tenant->default_currency,
            ), (string) $actor->id);

            $application->employee_id = $employee->id;
            $this->close($application, JobApplication::StatusHired, PipelineStage::OutcomeHired, $actor);

            if (isset($terms['onboarding_template_id'])) {
                $this->startOnboarding($employee, OnboardingTemplate::query()->findOrFail($terms['onboarding_template_id']), $terms['hire_date']);
            }

            return $employee;
        });
    }

    public function startOnboarding(Employee $employee, OnboardingTemplate $template, string $startDate): OnboardingChecklist
    {
        $checklist = OnboardingChecklist::create(['employee_id' => $employee->id, 'onboarding_template_id' => $template->id]);
        $start = CarbonImmutable::parse($startDate);

        foreach (array_values($template->tasks) as $index => $task) {
            OnboardingTask::create([
                'onboarding_checklist_id' => $checklist->id,
                'title' => $task['title'],
                'due_on' => $start->addDays((int) ($task['due_after_days'] ?? 0))->format('Y-m-d'),
                'sort_order' => $index,
            ]);
        }

        return $checklist;
    }

    public function completeTask(OnboardingTask $task, User $actor): OnboardingTask
    {
        DB::transaction(function () use ($task, $actor): void {
            $task->update(['completed_at' => $task->completed_at ?? CarbonImmutable::now(), 'completed_by' => $task->completed_by ?? $actor->id]);
            $open = OnboardingTask::query()->where('onboarding_checklist_id', $task->onboarding_checklist_id)->whereNull('completed_at')->exists();

            if (! $open) {
                OnboardingChecklist::query()->whereKey($task->onboarding_checklist_id)->update(['completed_at' => CarbonImmutable::now()]);
            }
        });

        return $task;
    }

    /**
     * Reads the application again under a row lock, so that of two concurrent decisions the second sees the first.
     */
    private function lockActive(JobApplication $application): JobApplication
    {
        $locked = JobApplication::query()->lockForUpdate()->findOrFail($application->id);
        $this->assertActive($locked);

        return $locked;
    }

    private function close(JobApplication $application, string $status, string $outcome, User $actor): void
    {
        $stage = $this->stages()->firstWhere('outcome', $outcome);
        $retention = $this->retentionDate();
        $consent = $application->consent_until?->format('Y-m-d');

        $application->fill([
            'status' => $status,
            'pipeline_stage_id' => $stage->id,
            'decided_at' => CarbonImmutable::now(),
            'retention_until' => $consent !== null && $consent > $retention ? $consent : $retention,
        ])->save();

        $this->recordStage($application, $stage, $actor);
    }

    private function retentionDate(): string
    {
        $days = RecruitingSetting::query()->value('retention_days') ?? RecruitingSetting::DefaultRetentionDays;

        return CarbonImmutable::now('Europe/Zurich')->addDays((int) $days)->format('Y-m-d');
    }

    private function recordStage(JobApplication $application, PipelineStage $stage, ?User $actor): void
    {
        ApplicationStageEvent::query()->create([
            'job_application_id' => $application->id,
            'pipeline_stage_id' => $stage->id,
            'actor_id' => $actor?->id,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    private function assertActive(JobApplication $application): void
    {
        if ($application->status !== JobApplication::StatusActive) {
            throw ValidationException::withMessages(['status' => 'La candidatura è già chiusa.']);
        }
    }
}
