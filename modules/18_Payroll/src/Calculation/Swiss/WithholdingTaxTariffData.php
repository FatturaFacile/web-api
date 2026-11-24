<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

use DateTimeImmutable;

/**
 * A row of an ESTV withholding tax tariff.
 */
final readonly class WithholdingTaxTariffData
{
    public function __construct(
        public string $canton,
        public string $tariffCode,
        public DateTimeImmutable $validFrom,
        public string $incomeFrom,
        public string $incomeStep,
        public string $minimumTax,
        public string $ratePercent,
    ) {}
}
