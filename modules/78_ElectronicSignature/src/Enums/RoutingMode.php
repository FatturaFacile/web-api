<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Enums;

enum RoutingMode: string
{
    case Sequential = 'sequential';
    case Parallel = 'parallel';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
