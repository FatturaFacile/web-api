<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Domain\Enums;

use Modules\CustomerSatisfaction\Domain\Enums\Concerns\EnumValues;

enum SubmittedVia: string
{
    use EnumValues;

    case OneClickLink = 'one_click_link';
    case LandingPage = 'landing_page';
    case WhatsappReply = 'whatsapp_reply';
    case Portal = 'portal';
}
