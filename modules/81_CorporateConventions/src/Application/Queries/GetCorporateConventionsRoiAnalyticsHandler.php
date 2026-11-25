<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Queries;

use Modules\CorporateConventions\Services\ConventionRoiService;

/**
 * ROI of the conventions: enrolment, revenue, discount, employer quota and margin per convention.
 */
final class GetCorporateConventionsRoiAnalyticsHandler
{
    public function __construct(
        private readonly ConventionRoiService $roi,
    ) {}

    /**
     * @return array{currency: string, kpis: array<string, mixed>, conventions: list<array<string, mixed>>}
     */
    public function handle(GetCorporateConventionsRoiAnalyticsQuery $query): array
    {
        return $this->roi->analytics($query->filters);
    }
}
