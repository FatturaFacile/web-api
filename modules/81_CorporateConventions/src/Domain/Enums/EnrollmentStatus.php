<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum EnrollmentStatus: string
{
    use EnumValues;

    case PendingOtp = 'PENDING_OTP';
    case Active = 'ACTIVE';
    case Revoked = 'REVOKED';
    case Expired = 'EXPIRED';
}
