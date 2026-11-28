<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Enums;

enum Jurisdiction: string
{
    case Italy = 'IT';
    case Switzerland = 'CH';
}
