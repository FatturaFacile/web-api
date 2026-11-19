<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum AuditActorType: string
{
    case User = 'USER';
    case System = 'SYSTEM';
    case ApiToken = 'API_TOKEN';
    case Webhook = 'WEBHOOK';
    case Cli = 'CLI';
}
