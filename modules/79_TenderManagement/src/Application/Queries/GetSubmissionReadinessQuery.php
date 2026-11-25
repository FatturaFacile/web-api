<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Queries;

use Carbon\CarbonImmutable;

/**
 * The submission readiness of a dossier: envelope completeness and blocking compliance findings.
 */
final readonly class GetSubmissionReadinessQuery
{
    public function __construct(
        public string $participationId,
        public ?CarbonImmutable $asOf = null,
    ) {}
}
