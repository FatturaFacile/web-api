<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum TargetStatus: string
{
    use Concerns\EnumValues;

    case Pending = 'pending';
    case Synced = 'synced';
    case Acknowledged = 'acknowledged';
    case Failed = 'failed';
}
