<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\ValueObjects;

/**
 * A holiday of a calendar day; a fraction below 1 is a half day (the afternoon before a feast, for example).
 */
final readonly class HolidayDay
{
    public function __construct(public string $name, public float $fraction = 1.0) {}
}
