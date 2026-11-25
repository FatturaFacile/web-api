<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum StoreType: string
{
    use Concerns\EnumValues;

    case Franchisee = 'franchisee';
    case DirectlyOperated = 'dos';
}
