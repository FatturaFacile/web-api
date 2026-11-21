<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Commands;

use Carbon\CarbonImmutable;
use Modules\Hse\Domain\Enums\FitnessOutcome;
use Modules\Hse\Domain\Enums\VisitType;

/**
 * Registers the judgment of the occupational physician. Clinical notes, when the caller may write them, go to the
 * segregated vault and never to the visit row.
 */
final readonly class RecordMedicalFitnessOutcomeCommand
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public string $doctorName,
        public ?string $doctorRegistrationNumber,
        public VisitType $visitType,
        public CarbonImmutable $visitDate,
        public ?CarbonImmutable $nextVisitDueDate,
        public FitnessOutcome $fitnessOutcome,
        public ?string $restrictionsSummary,
        public ?string $clinicalNotes,
        public ?string $certificateDocumentId,
        public ?string $userId,
    ) {}
}
