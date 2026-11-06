<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

/**
 * Why a reader let someone in or not. The value is the code the reader receives and the access log stores.
 */
enum AccessReason: string
{
    case Granted = 'granted';
    case ExitRecorded = 'exit_recorded';
    case UnknownCredential = 'unknown_credential';
    case InvalidSignature = 'invalid_signature';
    case CredentialRevoked = 'credential_revoked';
    case CredentialExpired = 'credential_expired';
    case MembershipMissing = 'membership_missing';
    case MembershipInactive = 'membership_inactive';
    case FeeOverdue = 'fee_overdue';
    case CertificateMissing = 'certificate_missing';
    case CertificateExpired = 'certificate_expired';
    case NoCourseNow = 'no_course_now';
    case CourseSuspended = 'course_suspended';
    case AreaFull = 'area_full';
    case AreaDisabled = 'area_disabled';

    public function isAllowed(): bool
    {
        return $this === self::Granted || $this === self::ExitRecorded;
    }
}
