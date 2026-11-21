<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\KpiDashboards\Domain\Enums\ComparisonMode;

/**
 * The global filter bar of a cockpit: period, comparison, legal entities (companies), cost centers, display currency and
 * the consolidation toggle.
 */
final readonly class CockpitFilter
{
    /**
     * @param  list<string>  $companyIds  empty means every legal entity of the tenant
     * @param  list<string>  $costCenterIds  empty means every cost center
     */
    public function __construct(
        public string $tenantId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ComparisonMode $comparison = ComparisonMode::Yoy,
        public array $companyIds = [],
        public array $costCenterIds = [],
        public string $displayCurrency = 'CHF',
        public bool $eliminateIntercompany = false,
    ) {}

    /**
     * Days of the period, both ends included.
     */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /**
     * The same filter over another period.
     */
    public function forPeriod(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($this->tenantId, $from, $to, $this->comparison, $this->companyIds, $this->costCenterIds, $this->displayCurrency, $this->eliminateIntercompany);
    }

    /**
     * Stable digest of everything that changes a payload.
     */
    public function hash(): string
    {
        return hash('xxh128', json_encode([
            $this->from->toDateString(), $this->to->toDateString(), $this->comparison->value,
            $this->sorted($this->companyIds), $this->sorted($this->costCenterIds), $this->displayCurrency, $this->eliminateIntercompany,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
