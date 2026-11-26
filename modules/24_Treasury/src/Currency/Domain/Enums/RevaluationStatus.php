<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Enums;

enum RevaluationStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';
}
