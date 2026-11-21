<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Commands;

use Carbon\CarbonImmutable;

/**
 * Saves a CFST 6508 / MSSL / ASA 10-point audit: each point is answered "yes", "partial" or "no".
 */
final readonly class SaveSafetyConceptAuditCommand
{
    /**
     * @param  array<string, string>  $answers  point number (1-10) => yes|partial|no
     * @param  list<array<string, mixed>>  $correctiveActions
     */
    public function __construct(
        public string $tenantId,
        public CarbonImmutable $auditDate,
        public array $answers,
        public array $correctiveActions = [],
    ) {}
}
