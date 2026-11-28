<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum CustomsStatus: string
{
    case NonUnionSuspended = 'NonUnion_Suspended';
    case UsualHandlingInProgress = 'UsualHandling_InProgress';
    case PartiallyDischarged = 'PartiallyDischarged';
    case FullyDischarged = 'FullyDischarged';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
