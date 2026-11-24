<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation;

/**
 * A line of a calculated payslip.
 */
final readonly class PayslipLineData
{
    public function __construct(
        public string $wageTypeCode,
        public string $description,
        public string $kind,
        public string $quantity,
        public string $rate,
        public string $amount,
        public int $sortOrder,
        public ?string $certificateBox = null,
    ) {}
}
