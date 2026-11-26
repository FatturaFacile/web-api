<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Commands;

use Modules\Treasury\Currency\Domain\Enums\RevaluationPeriodType;

/**
 * Computes the revaluation of the open items and currency accounts at a period end and keeps it as a draft to inspect.
 */
final readonly class SimulateFxRevaluationCommand
{
    /**
     * @param  list<string>|null  $currencies  limit to these currencies
     */
    public function __construct(
        public string $tenantId,
        public string $periodEnd,
        public RevaluationPeriodType $periodType,
        public ?array $currencies = null,
        public ?string $actorId = null,
    ) {}
}
