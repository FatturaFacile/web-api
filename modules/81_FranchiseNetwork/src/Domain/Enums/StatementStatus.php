<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum StatementStatus: string
{
    use Concerns\EnumValues;

    case Calculated = 'calculated';
    case Invoiced = 'invoiced';
    case Skipped = 'skipped';
}
