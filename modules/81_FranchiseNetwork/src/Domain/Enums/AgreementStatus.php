<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum AgreementStatus: string
{
    use Concerns\EnumValues;

    case Draft = 'draft';
    case Active = 'active';
    case Renewed = 'renewed';
    case Terminated = 'terminated';
    case Expired = 'expired';
}
