<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Enums;

enum CycleStatus: string
{
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Settled = 'SETTLED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
