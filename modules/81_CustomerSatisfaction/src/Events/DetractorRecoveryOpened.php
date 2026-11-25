<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Events;

/**
 * A detractor answer opened a closed-loop recovery case.
 */
final readonly class DetractorRecoveryOpened
{
    public function __construct(
        public string $caseId,
        public string $surveyResponseId,
        public string $customerId,
        public string $priority,
        public string $slaDueAt,
        public ?string $accountManagerId,
        public ?string $supportLeadId,
    ) {}
}
