<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Handlers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Hse\Application\Commands\RecordMedicalFitnessOutcomeCommand;
use Modules\Hse\Domain\Contracts\HseNotificationGatewayInterface;
use Modules\Hse\Domain\Enums\FitnessOutcome;
use Modules\Hse\Domain\Events\MedicalFitnessChangedEvent;
use Modules\Hse\Domain\Exceptions\HseNotFoundException;
use Modules\Hse\Infrastructure\Models\EmployeeClearanceCache;
use Modules\Hse\Infrastructure\Models\MedicalClinicalVaultEntry;
use Modules\Hse\Infrastructure\Models\OccupationalMedicalVisit;
use Modules\Hse\Infrastructure\Services\HseRecipientResolver;
use Modules\HumanResources\Models\Employee;

/**
 * Registers the physician judgment, refreshes the operational clearance cache and alerts at once when the judgment
 * is anything but "fit": the dispatchers learn about unfit workers and prescriptions without ever seeing a diagnosis.
 */
final class RecordMedicalFitnessOutcomeHandler
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly HseNotificationGatewayInterface $notifications,
        private readonly HseRecipientResolver $recipients,
    ) {}

    public function handle(RecordMedicalFitnessOutcomeCommand $command): OccupationalMedicalVisit
    {
        Employee::query()->where('tenant_id', $command->tenantId)->whereKey($command->employeeId)->exists() || throw new HseNotFoundException('Unknown employee.');

        $visit = DB::transaction(function () use ($command): OccupationalMedicalVisit {
            $visitId = (string) Str::uuid7();
            $vaultId = null;

            $visit = OccupationalMedicalVisit::create([
                'id' => $visitId,
                'tenant_id' => $command->tenantId,
                'employee_id' => $command->employeeId,
                'doctor_name' => $command->doctorName,
                'doctor_registration_number' => $command->doctorRegistrationNumber,
                'visit_type' => $command->visitType,
                'visit_date' => $command->visitDate->toDateString(),
                'next_visit_due_date' => $command->nextVisitDueDate?->toDateString(),
                'fitness_outcome' => $command->fitnessOutcome,
                'restrictions_summary' => $command->restrictionsSummary,
                'certificate_document_id' => $command->certificateDocumentId,
            ]);

            if ($command->clinicalNotes !== null && trim($command->clinicalNotes) !== '') {
                $vaultId = (string) MedicalClinicalVaultEntry::create([
                    'tenant_id' => $command->tenantId,
                    'medical_visit_id' => $visit->id,
                    'encrypted_notes' => $command->clinicalNotes,
                ])->id;
                $visit->update(['encrypted_clinical_notes_ref' => $vaultId]);
            }

            // The cache follows the most recent judgment only: an older visit entered late does not overwrite it.
            $latest = OccupationalMedicalVisit::query()->where('tenant_id', $command->tenantId)->where('employee_id', $command->employeeId)
                ->orderByDesc('visit_date')->orderByDesc('created_at')->first();

            if ($latest !== null && $latest->id === $visit->id) {
                EmployeeClearanceCache::updateOrCreate(
                    ['tenant_id' => $command->tenantId, 'employee_id' => $command->employeeId],
                    [
                        'medical_status' => $command->fitnessOutcome->value,
                        'restrictions_summary' => $command->restrictionsSummary,
                        'valid_until' => $command->nextVisitDueDate?->toDateString(),
                        'computed_at' => now(),
                    ],
                );
            }

            return $visit;
        });

        if ($command->fitnessOutcome !== FitnessOutcome::Fit) {
            $this->events->dispatch(new MedicalFitnessChangedEvent($command->tenantId, $command->employeeId, $command->fitnessOutcome->value, $command->restrictionsSummary));
            $this->notifications->notify(
                $command->tenantId,
                $this->recipients->forEmployee($command->tenantId, $command->employeeId),
                'hse_medical_fitness_changed',
                ['employee_id' => $command->employeeId, 'fitness_outcome' => $command->fitnessOutcome->value, 'restrictions_summary' => $command->restrictionsSummary],
            );
        }

        return $visit;
    }
}
