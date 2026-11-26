<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Commands;

/**
 * Right to erasure (GDPR art. 17, nLPD, Mod 096): deletes the pseudonym mappings of a data subject. The hash chain stays
 * valid (no entry changes) and the subject is shown as erased from then on.
 */
final readonly class EraseAuditSubjectCommand
{
    public function __construct(
        public string $tenantId,
        public string $subjectType,
        public string $subjectId,
        public ?string $requestedBy = null,
    ) {}
}
