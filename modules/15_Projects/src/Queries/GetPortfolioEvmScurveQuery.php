<?php

declare(strict_types=1);

namespace Modules\Projects\Queries;

/**
 * Cumulative PV, EV and AC over time with the EAC projection, for one project or a portfolio.
 */
final readonly class GetPortfolioEvmScurveQuery
{
    public const array Granularities = ['day', 'week', 'month'];

    /**
     * @param  list<string>  $projectIds  empty for every project of the company
     */
    public function __construct(
        public array $projectIds = [],
        public string $granularity = 'month',
        public ?string $from = null,
        public ?string $to = null,
    ) {}
}
