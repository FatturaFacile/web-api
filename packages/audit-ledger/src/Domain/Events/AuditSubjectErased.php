<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class AuditSubjectErased
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $subjectType,
        public string $subjectId,
        public int $erasedTokens,
    ) {}
}
