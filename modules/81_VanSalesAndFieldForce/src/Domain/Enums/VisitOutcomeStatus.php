<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Domain\Enums;

enum VisitOutcomeStatus: string
{
    case CompletedSale = 'completed_sale';
    case PresaleOrder = 'presale_order';
    case NoSale = 'no_sale';
    case Rescheduled = 'rescheduled';
    case CreditBlocked = 'credit_blocked';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
