<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Queries;

use Carbon\CarbonImmutable;

/**
 * Compares the requirements of a tender with the ERP track record and the qualification vault.
 */
final readonly class EvaluateTenderEligibilityAndGoNoGoQuery
{
    public function __construct(
        public string $participationId,
        public bool $persist = true,
        public ?CarbonImmutable $asOf = null,
    ) {}
}
