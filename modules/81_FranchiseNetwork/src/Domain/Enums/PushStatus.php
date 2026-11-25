<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum PushStatus: string
{
    use Concerns\EnumValues;

    case Draft = 'draft';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
}
