<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

enum LedgerPostingStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case NotPostable = 'not_postable';
}
