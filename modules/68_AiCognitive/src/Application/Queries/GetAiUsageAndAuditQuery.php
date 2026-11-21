<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Queries;

/**
 * Usage, cost and the audit trail of a period (the current month when no dates are given).
 */
final readonly class GetAiUsageAndAuditQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $eventType = null,
        public int $perPage = 50,
        public bool $verifyChain = false,
    ) {}
}
