<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum ActionStatus: string
{
    use Concerns\EnumValues;

    case Open = 'open';
    case Done = 'done';
}
