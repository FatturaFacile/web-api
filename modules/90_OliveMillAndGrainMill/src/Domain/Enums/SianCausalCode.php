<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Enums;

enum SianCausalCode: string
{
    case C01 = 'C01';
    case C02 = 'C02';
    case C03 = 'C03';
    case S01 = 'S01';
    case S02 = 'S02';
    case S03 = 'S03';
    case T01 = 'T01';
    case R01 = 'R01';

    /**
     * A load increases the stock of the destination tank.
     */
    public function isLoad(): bool
    {
        return in_array($this, [self::C01, self::C02, self::C03], true);
    }

    /**
     * An unload decreases the stock of the source tank.
     */
    public function isUnload(): bool
    {
        return in_array($this, [self::S01, self::S02, self::S03, self::R01], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
