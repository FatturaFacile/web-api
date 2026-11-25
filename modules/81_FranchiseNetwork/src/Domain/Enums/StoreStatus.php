<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum StoreStatus: string
{
    use Concerns\EnumValues;

    case Pipeline = 'pipeline';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
