<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Application\Queries;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Domain\Enums\QueryDomain;

final readonly class CompareBiTemporalCutOffDiffQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public string $tenantId,
        public QueryDomain $domain,
        public CarbonImmutable $validAt,
        public CarbonImmutable $knownAtFrom,
        public CarbonImmutable $knownAtTo,
        public array $filters = [],
    ) {}
}
