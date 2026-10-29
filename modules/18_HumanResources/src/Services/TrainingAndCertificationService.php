<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HumanResources\Contracts\TrainingAndCertificationServiceInterface;
use Modules\HumanResources\DTOs\AssignTrainingDTO;
use Modules\HumanResources\Events\CertificationExpiringEvent;
use Modules\HumanResources\Events\TrainingAssignedEvent;
use Modules\HumanResources\Events\TrainingBudgetForecastEvent;
use Modules\HumanResources\Events\TrainingCostReadyEvent;
use Modules\HumanResources\Models\Certification;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\TrainingCourse;
use Modules\HumanResources\Models\TrainingRecord;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;

final readonly class TrainingAndCertificationService implements TrainingAndCertificationServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private ?WorkflowEngineInterface $workflowEngine = null
    ) {}

    public function assignTraining(AssignTrainingDTO $dto, ?string $actorUserId = null): TrainingRecord
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $employee = Employee::findOrFail($dto->employeeId);
            $course = TrainingCourse::findOrFail($dto->courseId);

            $cost = $dto->overrideCost !== null ? $dto->overrideCost : (float) $course->cost_per_participant;
            $assignedDate = date('Y-m-d');
            $enrollmentNumber = 'TRN-'.date('Ymd').'-'.strtoupper(Str::random(5));

            $record = TrainingRecord::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'company_id' => $employee->company_id ?? $dto->tenantId,
                'employee_id' => $employee->id,
                'course_id' => $course->id,
                'enrollment_number' => $enrollmentNumber,
                'status' => 'assigned',
                'assigned_date' => $assignedDate,
                'scheduled_date' => $dto->scheduledDate ?? date('Y-m-d', strtotime('+14 days')),
                'cost_amount' => $cost,
                'currency' => (string) $course->currency,
                'notes' => $dto->notes,
            ]);

            if ($cost >= 1000.0 && $this->workflowEngine !== null) {
                $hasDef = WorkflowDefinition::where('tenant_id', $dto->tenantId)
                    ->where('code', 'HR_TRAINING_APPROVAL')
                    ->where('is_active', true)
                    ->exists();

                if ($hasDef) {
                    $initiatorId = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : (string) Str::uuid7();
                    $instance = $this->workflowEngine->startWorkflow(new StartWorkflowDTO(
                        workflowCode: 'HR_TRAINING_APPROVAL',
                        approvable: $record,
                        contextSnapshot: [
                            'enrollment_number' => $enrollmentNumber,
                            'cost_amount' => $cost,
                        ],
                        tenantId: $dto->tenantId,
                        companyId: $dto->tenantId,
                        initiatorUserId: $initiatorId
                    ));
                    $record->lockByWorkflow($instance);
                }
            }

            $this->auditLedger->record(
                action: 'HR_TRAINING_ASSIGNED',
                model: $record,
                payloadBefore: null,
                payloadAfter: $record->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            $companyId = (string) ($employee->company_id ?? $dto->tenantId);

            $assignedEvent = new TrainingAssignedEvent(
                trainingRecordId: (string) $record->id,
                enrollmentNumber: $enrollmentNumber,
                employeeId: (string) $employee->id,
                courseId: (string) $course->id,
                costAmount: $cost,
                assignedDate: $assignedDate,
                tenantId: $dto->tenantId,
                companyId: $companyId
            );
            $this->dispatcher->publish($assignedEvent);
            event($assignedEvent);

            if ($cost > 0.0) {
                $budgetEvent = new TrainingBudgetForecastEvent(
                    trainingRecordId: (string) $record->id,
                    courseId: (string) $course->id,
                    expectedOutflowAmount: $cost,
                    currency: (string) $course->currency,
                    expectedPaymentDate: date('Y-m-d', strtotime('+30 days')),
                    tenantId: $dto->tenantId,
                    companyId: $companyId
                );
                $this->dispatcher->publish($budgetEvent);
                event($budgetEvent);
            }

            return $record->fresh();
        });
    }

    public function completeTraining(TrainingRecord $trainingRecord, float $score = 100.0, ?string $actorUserId = null): TrainingRecord
    {
        return DB::transaction(function () use ($trainingRecord, $score) {
            $locked = TrainingRecord::where('id', $trainingRecord->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();
            $course = TrainingCourse::findOrFail($locked->course_id);

            $locked->update([
                'status' => 'completed',
                'completed_date' => date('Y-m-d'),
                'score' => $score,
                'is_locked' => false,
                'certificate_issued' => true,
            ]);

            $validityMonths = (int) ($course->validity_months ?: 24);
            $expiryDate = date('Y-m-d', strtotime("+{$validityMonths} months"));

            $this->issueCertification(
                tenantId: (string) $locked->tenant_id,
                employeeId: (string) $locked->employee_id,
                code: 'CERT-'.$course->course_code,
                name: $course->title.' Certificate',
                expiryDate: $expiryDate,
                courseId: (string) $course->id
            );

            if ((float) $locked->cost_amount > 0.0) {
                $costReadyEvent = new TrainingCostReadyEvent(
                    trainingRecordId: (string) $locked->id,
                    employeeId: (string) $locked->employee_id,
                    courseId: (string) $course->id,
                    costAmount: (float) $locked->cost_amount,
                    currency: (string) $locked->currency,
                    glExpenseAccountCode: '6180',
                    tenantId: (string) $locked->tenant_id,
                    companyId: (string) ($locked->company_id ?? $locked->tenant_id)
                );
                $this->dispatcher->publish($costReadyEvent);
                event($costReadyEvent);
            }

            $this->auditLedger->record(
                action: 'HR_TRAINING_COMPLETED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            return $locked->fresh();
        });
    }

    public function issueCertification(
        string $tenantId,
        string $employeeId,
        string $code,
        string $name,
        string $expiryDate,
        ?string $courseId = null
    ): Certification {
        return Certification::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'employee_id' => $employeeId,
            'course_id' => $courseId,
            'code' => $code,
            'name' => $name,
            'certificate_number' => 'CRT-'.date('Ymd').'-'.strtoupper(Str::random(5)),
            'issuing_authority' => 'Enterprise Compliance Academy',
            'issue_date' => date('Y-m-d'),
            'expiry_date' => $expiryDate,
            'status' => 'active',
            'is_mandatory' => true,
        ]);
    }

    public function scanExpiringCertifications(string $tenantId, int $withinDays = 30): int
    {
        return DB::transaction(function () use ($tenantId, $withinDays) {
            $thresholdDate = date('Y-m-d', strtotime("+{$withinDays} days"));

            $certifications = Certification::where('tenant_id', $tenantId)
                ->whereNotNull('employee_id')
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', $thresholdDate)
                ->whereIn('status', ['active', 'expiring_soon'])
                ->lockForUpdate()
                ->get();

            $alerted = 0;
            foreach ($certifications as $cert) {
                $daysLeft = (int) floor((strtotime($cert->expiry_date->format('Y-m-d')) - time()) / 86400);
                $newStatus = $daysLeft < 0 ? 'expired' : 'expiring_soon';

                $cert->update([
                    'status' => $newStatus,
                    'renewal_alert_sent_at' => now(),
                ]);

                $expEvent = new CertificationExpiringEvent(
                    certificationId: (string) $cert->id,
                    employeeId: (string) $cert->employee_id,
                    certificationCode: (string) $cert->code,
                    certificationName: (string) $cert->name,
                    expiryDate: $cert->expiry_date->format('Y-m-d'),
                    daysUntilExpiry: $daysLeft,
                    tenantId: $tenantId,
                    companyId: (string) ($cert->company_id ?? $tenantId)
                );
                $this->dispatcher->publish($expEvent);
                event($expEvent);

                $alerted++;
            }

            return $alerted;
        });
    }
}
