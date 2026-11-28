<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Enums;

enum SettlementStatus: string
{
    case Posted = 'posted';
}
