<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Domain\Enums;

/**
 * The traffic light of an indicator: green (healthy), yellow (watch) and red (alert), ordered by severity.
 */
enum TrafficLight: string
{
    case Green = 'green';
    case Yellow = 'yellow';
    case Red = 'red';

    public function severity(): int
    {
        return match ($this) {
            self::Green => 0,
            self::Yellow => 1,
            self::Red => 2,
        };
    }

    /**
     * The most severe light of a list; green when the list is empty.
     *
     * @param  iterable<self|string>  $lights
     */
    public static function worst(iterable $lights): self
    {
        $worst = self::Green;

        foreach ($lights as $light) {
            $light = $light instanceof self ? $light : self::from($light);
            $worst = $light->severity() > $worst->severity() ? $light : $worst;
        }

        return $worst;
    }
}
