<?php

declare(strict_types=1);

namespace Modules\HumanResources\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HumanResources\Contracts\CandidateEmailSenderInterface;
use Modules\HumanResources\Contracts\EmployeeLifecycleServiceInterface;
use Modules\HumanResources\Contracts\OnboardingStarterInterface;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\DTOs\HireCandidateDTO;
use Modules\HumanResources\DTOs\HireEmployeeDTO;
use Modules\HumanResources\DTOs\OnboardingStartResultDTO;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\JobOpeningStatus;
use Modules\HumanResources\Enums\OfferStatus;
use Modules\HumanResources\Enums\OnboardingStatus;
use Modules\HumanResources\Events\CandidateHiredEvent;
use Modules\HumanResources\Events\CandidateStageChangedEvent;
use Modules\HumanResources\Exceptions\RecruitingRuleViolationException;
use Modules\HumanResources\Models\ApplicationStageHistory;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\JobOffer;
use Modules\HumanResources\Models\JobOpening;
use Modules\HumanResources\Models\Position;

/**
 * `HireCandidateAndCreateEmployeeCommand`: converts a candidate with a signed offer into an employee in a single
 * database transaction. The employee, contract, lifecycle event and `EmployeeHiredEvent` are produced by the
 * existing `EmployeeLifecycleServiceInterface::hireEmployee`; any failure rolls everything back.
 */
final readonly class HireCandidateAndCreateEmployeeAction
{
    public function __construct(
        private EmployeeLifecycleServiceInterface $lifecycleService,
        private OnboardingStarterInterface $onboardingStarter,
        private RecruitmentServiceInterface $recruitment,
        private CandidateEmailSenderInterface $emailSender
    ) {}

    /**
     * @return array{application: CandidateApplication, employee: Employee, job_opening: JobOpening, onboarding: OnboardingStartResultDTO}
     */
    public function execute(HireCandidateDTO $dto): array
    {
        $result = DB::transaction(function () use ($dto): array {
            /** @var CandidateApplication $application */
            $application = CandidateApplication::query()->lockForUpdate()->findOrFail($dto->applicationId);

            if ($application->isAnonymized()) {
                throw RecruitingRuleViolationException::candidateAnonymized();
            }

            if ($application->isHired()) {
                throw RecruitingRuleViolationException::alreadyHired();
            }

            if ($application->stage === ApplicationStage::Rejected) {
                throw RecruitingRuleViolationException::invalidStageTransition(ApplicationStage::Rejected->value, ApplicationStage::Hired->value);
            }

            /** @var JobOffer|null $offer */
            $offer = $application->offers()
                ->where('status', OfferStatus::Signed->value)
                ->latest('signed_at')
                ->first();

            if ($offer === null) {
                throw RecruitingRuleViolationException::offerNotSigned();
            }

            /** @var JobOpening $opening */
            $opening = JobOpening::query()->lockForUpdate()->findOrFail($application->job_opening_id);

            if (! $opening->status->isOpen()) {
                throw RecruitingRuleViolationException::openingNotOpen();
            }

            /** @var Position|null $position */
            $position = Position::query()->lockForUpdate()->find($opening->position_id);
            if ($position === null || $opening->remainingOpenings() < 1 || $this->recruitment->availableVacancy($position, $opening) < 1) {
                throw RecruitingRuleViolationException::noVacancy();
            }

            /** @var Department|null $department */
            $department = $position->department_id !== null ? Department::query()->find($position->department_id) : null;
            $tenantId = (string) $application->tenant_id;

            $employee = $this->lifecycleService->hireEmployee(new HireEmployeeDTO(
                tenantId: $tenantId,
                companyId: $tenantId,
                firstName: (string) $application->first_name,
                lastName: (string) $application->last_name,
                workEmail: (string) ($dto->workEmail ?? $application->email),
                baseAnnualSalary: (float) $offer->proposed_salary,
                hireDate: $dto->hireDate ?? $offer->start_date->toDateString(),
                departmentId: $position->department_id !== null ? (string) $position->department_id : null,
                businessUnitId: $department?->business_unit_id !== null ? (string) $department->business_unit_id : null,
                costCenterId: $department?->cost_center_id !== null ? (string) $department->cost_center_id : null,
                positionId: (string) $position->id,
                jobGradeId: $position->job_grade_id !== null ? (string) $position->job_grade_id : null,
                managerId: $dto->managerId ?? ($department?->manager_employee_id !== null ? (string) $department->manager_employee_id : null),
                employmentType: (string) $offer->employment_type,
                contractType: (string) $offer->contract_type,
                weeklyHours: (float) $offer->weekly_hours,
                probationMonths: (int) $offer->probation_months,
                currency: (string) $offer->currency,
                metadata: [
                    'recruiting_application_id' => (string) $application->id,
                    'recruiting_job_opening_id' => (string) $opening->id,
                ]
            ), $dto->actorUserId);

            $employee->forceFill([
                'personal_email' => $application->email,
                'phone' => $application->phone,
            ])->save();

            $from = $application->stage;
            $application->forceFill([
                'stage' => ApplicationStage::Hired,
                'stage_entered_at' => now(),
                'board_position' => $this->recruitment->nextBoardPosition((string) $opening->id, ApplicationStage::Hired),
                'hired_employee_id' => $employee->id,
                'privacy_consent_expires_at' => null,
            ])->save();

            $actorUserId = $dto->actorUserId !== null && Str::isUuid($dto->actorUserId) ? $dto->actorUserId : null;
            ApplicationStageHistory::create([
                'application_id' => $application->id,
                'from_stage' => $from->value,
                'to_stage' => ApplicationStage::Hired->value,
                'actor_user_id' => $actorUserId,
            ]);

            $opening->hires_count = (int) $opening->hires_count + 1;
            if ($opening->hires_count >= (int) $opening->openings_count) {
                $opening->status = JobOpeningStatus::ClosedFilled;
                $opening->closed_at = now();
            }
            $opening->save();

            $onboarding = $dto->startOnboarding
                ? $this->onboardingStarter->start($employee, $application)
                : new OnboardingStartResultDTO(OnboardingStatus::NotAvailable);

            event(new CandidateStageChangedEvent(
                applicationId: (string) $application->id,
                jobOpeningId: (string) $opening->id,
                fromStage: $from->value,
                toStage: ApplicationStage::Hired->value,
                actorUserId: $actorUserId,
                tenantId: $tenantId,
                companyId: $tenantId
            ));

            event(new CandidateHiredEvent(
                applicationId: (string) $application->id,
                jobOpeningId: (string) $opening->id,
                employeeId: (string) $employee->id,
                positionId: (string) $position->id,
                hireDate: $employee->hire_date?->toDateString() ?? (string) ($dto->hireDate ?? $offer->start_date->toDateString()),
                tenantId: $tenantId,
                companyId: $tenantId
            ));

            return [
                'application' => $application,
                'employee' => $employee->refresh(),
                'job_opening' => $opening,
                'onboarding' => $onboarding,
            ];
        });

        if ($this->recruitment->settings()->stageAutoEmail(ApplicationStage::Hired)) {
            $this->emailSender->sendStageEmail($result['application'], ApplicationStage::Hired);
        }

        return $result;
    }
}
