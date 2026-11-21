<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Events;

/**
 * The physician judgment of an employee changed in a way that may affect the current machine or site assignments.
 * It carries the operational outcome only, never clinical data.
 */
final readonly class MedicalFitnessChangedEvent
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public string $fitnessOutcome,
        public ?string $restrictionsSummary,
    ) {}
}
