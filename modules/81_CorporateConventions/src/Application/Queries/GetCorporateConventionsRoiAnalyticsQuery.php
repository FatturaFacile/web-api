<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Queries;

/**
 * ROI of the conventions: enrolment, revenue, discount, employer quota and margin per convention.
 */
final readonly class GetCorporateConventionsRoiAnalyticsQuery
{
    public function __construct(
        /** @var array<string, mixed> */
        public array $filters = [],
    ) {}
}
