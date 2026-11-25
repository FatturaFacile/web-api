<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum CollectionScheme: string
{
    use Concerns\EnumValues;

    case SepaSdd = 'sepa_sdd';
    case LsvPlus = 'lsv_plus';
}
