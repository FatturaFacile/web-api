<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Enums;

enum VerificationOutcome: string
{
    case Valid = 'valid';
    case BrokenAt = 'broken_at';
    case MissingRange = 'missing_range';
}
