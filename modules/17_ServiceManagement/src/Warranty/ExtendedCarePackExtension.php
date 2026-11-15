<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Warranty;

use Carbon\CarbonImmutable;

/**
 * Extended care pack applied on top of the statutory warranty. Effective expiry = max(base, pack end).
 */
final readonly class ExtendedCarePackExtension
{
    public function __construct(
        public string $name,
        public int $months,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    public static function fromStart(string $name, int $months, CarbonImmutable $startsAt): self
    {
        return new self($name, $months, $startsAt, $startsAt->addMonthsNoOverflow($months));
    }
}
