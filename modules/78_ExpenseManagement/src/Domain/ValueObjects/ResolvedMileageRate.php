<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\ValueObjects;

use Modules\ExpenseManagement\Domain\Enums\CalculationSource;

final readonly class ResolvedMileageRate
{
    public function __construct(
        public CalculationSource $source,
        public string $ratePerKm,
        public string $currency,
        public ?string $ratePeriod = null,
        public ?string $effectiveDate = null,
    ) {}
}
