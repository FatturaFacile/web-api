<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Domain\Enums;

use Modules\CustomerSatisfaction\Domain\Enums\Concerns\EnumValues;

enum SurveyChannel: string
{
    use EnumValues;

    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
}
