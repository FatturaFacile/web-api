<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation;

/**
 * A variable input of the month.
 */
final readonly class PayrollInputData
{
    public function __construct(
        public string $wageTypeCode,
        public string $quantity = '0',
        public string $rate = '0',
        public string $amount = '0',
        public ?string $note = null,
    ) {}
}
