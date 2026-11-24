<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

/**
 * The state of a participant at a glance. Settled: everything due is paid. Attention: no fee assigned yet, or a part
 * paid that is not late. Overdue: something due is not paid.
 */
enum FeeStanding: string
{
    case Settled = 'settled';
    case Attention = 'attention';
    case Overdue = 'overdue';

    public function color(): string
    {
        return match ($this) {
            self::Settled => 'green',
            self::Attention => 'orange',
            self::Overdue => 'red',
        };
    }
}
