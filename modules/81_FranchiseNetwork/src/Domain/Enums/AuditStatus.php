<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum AuditStatus: string
{
    use Concerns\EnumValues;

    case Draft = 'draft';
    case Submitted = 'submitted';
}
