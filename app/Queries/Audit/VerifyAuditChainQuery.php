<?php

declare(strict_types=1);

namespace App\Queries\Audit;

/**
 * Verify the audit chain of a tenant (null: the system level chain), optionally over a sequence range.
 */
final readonly class VerifyAuditChainQuery
{
    public function __construct(
        public ?string $tenantId,
        public ?int $fromSequence = null,
        public ?int $toSequence = null,
    ) {}
}
