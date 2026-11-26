<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Commands;

/**
 * Reverses a posted revaluation (on the first day of the next period unless a date is given), so that the next period starts
 * again from the historical book values.
 */
final readonly class ReverseFxRevaluationCommand
{
    public function __construct(
        public string $tenantId,
        public string $revaluationId,
        public ?string $reversalDate = null,
        public ?string $actorId = null,
    ) {}
}
