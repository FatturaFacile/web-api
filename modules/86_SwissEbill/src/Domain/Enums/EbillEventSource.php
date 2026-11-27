<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Enums;

enum EbillEventSource: string
{
    case System = 'system';
    case User = 'user';
    case GatewayWebhook = 'gateway_webhook';
    case GatewayPoll = 'gateway_poll';
    case Camt054 = 'camt054';
}
