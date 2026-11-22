<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A closure of a supplier (or of every supplier when supplierId is null): Ferragosto in Italy, summer or winter factory
 * holidays in Switzerland, public holidays.
 */
final readonly class ShutdownPeriod
{
    public function __construct(
        public ?string $supplierId,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $label = '',
    ) {}

    public function covers(CarbonImmutable $day, ?string $supplierId): bool
    {
        if ($this->supplierId !== null && $this->supplierId !== $supplierId) {
            return false;
        }

        return $day->startOfDay()->betweenIncluded($this->start->startOfDay(), $this->end->startOfDay());
    }
}
