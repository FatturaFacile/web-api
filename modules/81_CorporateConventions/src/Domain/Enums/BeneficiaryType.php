<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum BeneficiaryType: string
{
    use EnumValues;

    case PrimaryEmployee = 'PRIMARY_EMPLOYEE';
    case FamilyMember = 'FAMILY_MEMBER';
}
