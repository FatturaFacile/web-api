<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Enums;

enum TrafficLight: string
{
    case Green = 'GREEN';
    case Yellow = 'YELLOW';
    case Red = 'RED';

    /** The worse of two lights. */
    public function worst(self $other): self
    {
        $rank = [self::Green->value => 0, self::Yellow->value => 1, self::Red->value => 2];

        return $rank[$other->value] > $rank[$this->value] ? $other : $this;
    }
}
