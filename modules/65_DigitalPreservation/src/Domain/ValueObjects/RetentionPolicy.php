<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\ValueObjects;

use App\Services\Privacy\StatutoryRetentionCatalogue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Statutory retention lock of a batch: never shorter than the fiscal record period of the catalogue (10 years).
 */
final readonly class RetentionPolicy
{
    public int $years;

    public function __construct(int $requestedYears = StatutoryRetentionCatalogue::FISCAL_RECORD_YEARS)
    {
        $this->years = max($requestedYears, StatutoryRetentionCatalogue::FISCAL_RECORD_YEARS);
    }

    /**
     * Last day of the lock, counted from the end of the period of the batch.
     */
    public function lockUntil(CarbonInterface $periodEnd): CarbonImmutable
    {
        return CarbonImmutable::parse($periodEnd)->startOfDay()->addYearsNoOverflow($this->years);
    }

    /**
     * Whether the lock is still running on the given day.
     */
    public function isLocked(CarbonInterface $lockUntil, ?CarbonInterface $now = null): bool
    {
        return CarbonImmutable::parse($now ?? now())->startOfDay()->lessThanOrEqualTo(CarbonImmutable::parse($lockUntil)->startOfDay());
    }
}
