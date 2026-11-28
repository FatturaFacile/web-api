<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Enums;

enum InlNotificationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
}
