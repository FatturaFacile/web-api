<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\ValueObjects;

use Modules\ExpenseManagement\Domain\Enums\AllowanceMethod;
use Modules\ExpenseManagement\Domain\Enums\DestinationType;

final readonly class PerDiemFacts
{
    public function __construct(
        public string $countryCode,
        public DestinationType $destinationType,
        public AllowanceMethod $method,
        public string $daysCount,
        public ?string $ratePerDay,
        public bool $lodgingReimbursed,
        public bool $mealsReimbursed,
        public ?string $policyDailyLimit = null,
    ) {}
}
