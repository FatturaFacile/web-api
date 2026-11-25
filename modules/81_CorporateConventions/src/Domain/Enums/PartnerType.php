<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum PartnerType: string
{
    use EnumValues;

    case Corporate = 'CORPORATE';
    case Cral = 'CRAL';
    case ProfessionalOrder = 'PROFESSIONAL_ORDER';
    case Union = 'UNION';
    case University = 'UNIVERSITY';
    case PublicBody = 'PUBLIC_BODY';
    case Insurance = 'INSURANCE';
    case Association = 'ASSOCIATION';
}
