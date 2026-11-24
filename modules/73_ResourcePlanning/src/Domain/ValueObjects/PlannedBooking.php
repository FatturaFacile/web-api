<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Domain\Enums\BookingType;

/**
 * A booking as the load engine reads it: an allocation row, or a hypothetical one when a candidate is evaluated.
 */
final readonly class PlannedBooking
{
    public function __construct(
        public string $employeeId,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public float $hoursPerDay,
        public BookingType $type,
        public bool $billable,
    ) {}
}
