<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Enums;

enum BlockingReasonCode: string
{
    case CertificateMissing = 'certificate_missing';
    case CertificateExpired = 'certificate_expired';
    case MedicalMissing = 'medical_missing';
    case MedicalExpired = 'medical_expired';
    case MedicalUnfit = 'medical_unfit';
    case MedicalRestrictionConflict = 'medical_restriction_conflict';
    case PpeMissing = 'ppe_missing';
}
