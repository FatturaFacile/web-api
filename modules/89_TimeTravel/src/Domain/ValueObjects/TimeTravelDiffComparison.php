<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Domain\Enums\QueryDomain;
use Modules\TimeTravel\Support\Instant;

/**
 * The difference of one valid-time cut-off between two moments of knowledge, with the backdated entries that explain it.
 */
final readonly class TimeTravelDiffComparison
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $backdatedEntries
     * @param  array{before: string, after: string, delta: string}  $totals
     */
    public function __construct(
        public QueryDomain $domain,
        public CarbonImmutable $validAt,
        public CarbonImmutable $knownAtFrom,
        public CarbonImmutable $knownAtTo,
        public array $rows,
        public array $backdatedEntries,
        public array $totals,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain->value,
            'valid_at' => Instant::iso($this->validAt),
            'known_at_from' => Instant::iso($this->knownAtFrom),
            'known_at_to' => Instant::iso($this->knownAtTo),
            'rows' => $this->rows,
            'backdated_entries' => $this->backdatedEntries,
            'totals' => $this->totals,
        ];
    }
}
