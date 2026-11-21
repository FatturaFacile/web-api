<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Queries;

use Carbon\CarbonImmutable;

/**
 * Everything that expires (or already expired) within the horizon: the feed of the daily scan and of the dashboard widgets.
 */
final readonly class GetExpiringSafetyCertificatesAndMedicalVisitsQuery
{
    public function __construct(
        public string $tenantId,
        public CarbonImmutable $today,
        public int $withinDays = 60,
    ) {}
}
