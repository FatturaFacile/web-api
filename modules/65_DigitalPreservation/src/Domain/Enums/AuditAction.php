<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Enums;

/**
 * Actions recorded in the append-only preservation audit log.
 */
enum AuditAction: string
{
    case Ingested = 'ingested';
    case PdfaConverted = 'pdfa_converted';
    case PdvGenerated = 'pdv_generated';
    case Sealed = 'sealed';
    case Timestamped = 'timestamped';
    case Dispatched = 'dispatched';
    case RdvReceived = 'rdv_received';
    case PddExported = 'pdd_exported';
    case IntegrityVerified = 'integrity_verified';
    case IntegrityAlert = 'integrity_alert';
    case DeletionBlocked = 'deletion_blocked';
}
