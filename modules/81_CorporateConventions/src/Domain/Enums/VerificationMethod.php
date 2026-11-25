<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum VerificationMethod: string
{
    use EnumValues;

    case WorkEmailOtp = 'WORK_EMAIL_OTP';
    case MembershipCardPrefix = 'MEMBERSHIP_CARD_PREFIX';
    case HashedEmployeeList = 'HASHED_EMPLOYEE_LIST';
    case PromoCode = 'PROMO_CODE';
}
