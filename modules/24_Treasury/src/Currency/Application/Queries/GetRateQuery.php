<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Queries;

use Modules\Treasury\Currency\Domain\Enums\RateType;

/**
 * The rate applying to a pair on a day (the rate of the day, the latest earlier one within five days, or the contract rate of a
 * reference).
 */
final readonly class GetRateQuery
{
    public function __construct(
        public ?string $tenantId,
        public string $base,
        public string $quote,
        public string $date,
        public RateType $type = RateType::Mid,
        public ?string $contractReference = null,
    ) {}
}
