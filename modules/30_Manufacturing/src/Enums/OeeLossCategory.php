<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Enums;

/**
 * The six big losses of lean manufacturing: the first two reduce availability, the next two performance and the last
 * two quality.
 */
enum OeeLossCategory: string
{
    case EquipmentFailure = 'equipment_failure';
    case SetupAdjustment = 'setup_adjustment';
    case MinorStops = 'minor_stops';
    case ReducedSpeed = 'reduced_speed';
    case ProcessDefects = 'process_defects';
    case StartupRejects = 'startup_rejects';

    /**
     * The OEE factor the loss reduces.
     */
    public function factor(): string
    {
        return match ($this) {
            self::EquipmentFailure, self::SetupAdjustment => 'availability',
            self::MinorStops, self::ReducedSpeed => 'performance',
            self::ProcessDefects, self::StartupRejects => 'quality',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
