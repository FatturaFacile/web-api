<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts\Payroll;

use DateTimeInterface;
use Modules\HumanResources\DTOs\Payroll\WithholdingTariffData;
use Modules\HumanResources\Exceptions\Payroll\MissingWithholdingTariffException;

interface WithholdingTaxServiceInterface
{
    /**
     * Effective tariff code once the church tax variant is applied (A0N + church tax = A0Y).
     */
    public function effectiveTariffCode(string $tariffCode, bool $churchTax): string;

    /**
     * @throws MissingWithholdingTariffException
     */
    public function resolveTariff(string $canton, string $tariffCode, bool $churchTax, DateTimeInterface $date, ?string $employeeId = null): WithholdingTariffData;

    /**
     * Withholding tax in Rappen (rounded to 0.05 CHF) for a monthly taxable income.
     *
     * @throws MissingWithholdingTariffException
     */
    public function taxFor(WithholdingTariffData $tariff, int $taxableIncomeRappen, ?string $employeeId = null): int;
}
