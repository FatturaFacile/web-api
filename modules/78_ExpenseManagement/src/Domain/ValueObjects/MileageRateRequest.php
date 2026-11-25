<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\ValueObjects;

use Carbon\CarbonInterface;
use Modules\ExpenseManagement\Domain\Enums\CalculationSource;

final readonly class MileageRateRequest
{
    public function __construct(
        public string $vehicleClassCode,
        public CarbonInterface $onDate,
        public ?int $annualKmBracket = null,
        public ?CalculationSource $requestedSource = null,
        public ?string $policyRatePerKm = null,
    ) {}
}
