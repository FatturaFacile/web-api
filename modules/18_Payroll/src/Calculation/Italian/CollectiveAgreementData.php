<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Italian;

/**
 * A collective agreement (CCNL).
 */
final readonly class CollectiveAgreementData
{
    public function __construct(
        public int $installments = 13,
        public int $fourteenthMonth = 7,
        public ?string $employerInpsRatePercent = null,
    ) {}
}
