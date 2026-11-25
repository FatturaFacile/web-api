<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Domain\Enums;

enum SettlementStatus: string
{
    case Draft = 'draft';
    case Reconciled = 'reconciled';
    case Posted = 'posted';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
