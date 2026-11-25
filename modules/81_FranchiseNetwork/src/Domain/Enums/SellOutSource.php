<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum SellOutSource: string
{
    use Concerns\EnumValues;

    case Pos = 'pos';
    case FiscalPrinter = 'fiscal_printer';
    case Manual = 'manual';
}
