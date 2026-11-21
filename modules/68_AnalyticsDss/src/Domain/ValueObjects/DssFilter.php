<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Domain\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The global strategic filter of every DSS query: scope (legal entities, cost centers), reporting currency, period and the
 * comparison baseline. The period is resolved from the preset and the `as of` day, or taken from the custom range.
 */
final readonly class DssFilter
{
    public const array Presets = ['MTD', 'QTD', 'YTD', 'L12M', 'CUSTOM'];

    public const array Baselines = ['PRIOR_PERIOD', 'PRIOR_YEAR', 'BUDGET'];

    public string $from;

    public string $to;

    /**
     * @param  list<string>  $legalEntityIds
     * @param  list<string>  $costCenterIds
     */
    public function __construct(
        public string $tenantId,
        public array $legalEntityIds = [],
        public array $costCenterIds = [],
        public string $currency = 'CHF',
        public string $periodPreset = 'YTD',
        public string $comparisonBaseline = 'PRIOR_YEAR',
        string $asOf = '',
        ?string $customFrom = null,
        ?string $customTo = null,
    ) {
        if (! in_array($currency, ['CHF', 'EUR'], true)) {
            throw new InvalidArgumentException("Unsupported reporting currency [{$currency}].");
        }

        $asOfDay = new DateTimeImmutable($asOf !== '' ? $asOf : 'today');

        [$this->from, $this->to] = match ($periodPreset) {
            'MTD' => [$asOfDay->modify('first day of this month')->format('Y-m-d'), $asOfDay->format('Y-m-d')],
            'QTD' => [$asOfDay->setDate((int) $asOfDay->format('Y'), (int) (3 * floor(((int) $asOfDay->format('n') - 1) / 3) + 1), 1)->format('Y-m-d'), $asOfDay->format('Y-m-d')],
            'YTD' => [$asOfDay->format('Y').'-01-01', $asOfDay->format('Y-m-d')],
            'L12M' => [$asOfDay->modify('-12 months +1 day')->format('Y-m-d'), $asOfDay->format('Y-m-d')],
            'CUSTOM' => [(string) ($customFrom ?? $asOfDay->format('Y-m-01')), (string) ($customTo ?? $asOfDay->format('Y-m-d'))],
            default => throw new InvalidArgumentException("Unsupported period preset [{$periodPreset}]."),
        };

        if ($this->from > $this->to) {
            throw new InvalidArgumentException('The period start is after its end.');
        }
    }

    public function days(): int
    {
        return (int) (new DateTimeImmutable($this->from))->diff(new DateTimeImmutable($this->to))->days + 1;
    }

    /**
     * Range of the comparison baseline. For a budget baseline the range is the current one (the budget is read for it).
     *
     * @return array{0: string, 1: string}
     */
    public function comparisonRange(): array
    {
        $from = new DateTimeImmutable($this->from);
        $to = new DateTimeImmutable($this->to);

        return match ($this->comparisonBaseline) {
            'PRIOR_YEAR' => [$from->modify('-1 year')->format('Y-m-d'), $to->modify('-1 year')->format('Y-m-d')],
            'PRIOR_PERIOD' => [$from->modify('-'.$this->days().' days')->format('Y-m-d'), $from->modify('-1 day')->format('Y-m-d')],
            default => [$this->from, $this->to],
        };
    }

    public function withBaseline(string $baseline): self
    {
        return new self($this->tenantId, $this->legalEntityIds, $this->costCenterIds, $this->currency, 'CUSTOM', $baseline, '', $this->from, $this->to);
    }

    public function cacheKey(string $scope): string
    {
        $entities = $this->legalEntityIds;
        $centers = $this->costCenterIds;
        sort($entities);
        sort($centers);

        return $scope.':'.md5(json_encode([$entities, $centers, $this->currency, $this->from, $this->to, $this->comparisonBaseline], JSON_THROW_ON_ERROR));
    }
}
