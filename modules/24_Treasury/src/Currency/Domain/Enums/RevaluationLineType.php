<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Enums;

enum RevaluationLineType: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';
    case Bank = 'bank';
    case Cash = 'cash';

    /**
     * A liability is carried with a negative balance, so that one formula serves every line: a positive difference is a gain.
     */
    public function isLiability(): bool
    {
        return $this === self::Payable;
    }
}
