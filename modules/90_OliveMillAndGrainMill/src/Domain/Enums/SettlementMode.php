<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Enums;

enum SettlementMode: string
{
    case CashOrBank = 'CASH_OR_BANK';
    case FullInKindMolenda = 'FULL_IN_KIND_MOLENDA';
    case HybridMolenda = 'HYBRID_MOLENDA';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
