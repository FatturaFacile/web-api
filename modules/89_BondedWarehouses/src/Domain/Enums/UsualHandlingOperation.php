<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum UsualHandlingOperation: string
{
    case Repacking = 'Repacking';
    case Labeling = 'Labeling';
    case Kitting = 'Kitting';
    case Sorting = 'Sorting';
    case Cleaning = 'Cleaning';
    case Testing = 'Testing';
    case Marking = 'Marking';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
