<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Queries;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * The exact subordination (Rangrücktritt) still needed to cure the over-indebtedness of Art. 725b CO, with the accrued and the future
 * contractual interest over the deferral horizon.
 */
final readonly class CalculateRequiredRangruecktrittAmountQuery
{
    public function __construct(
        public Tenant $tenant,
        public int $horizonMonths = 12,
        public ?string $interestRatePercent = null,
        public ?string $basis = null,
        public ?CarbonImmutable $asOf = null,
    ) {}
}
