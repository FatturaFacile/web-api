<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions\Payroll;

final class MissingWithholdingTariffException extends PayrollCalculationException
{
    public static function for(string $canton, string $tariffCode, string $date, ?string $employeeId = null): self
    {
        $field = $employeeId !== null ? "employees.{$employeeId}.qst_tariff_code" : 'qst_tariff_code';

        return new self(
            detail: "No withholding tax tariff {$tariffCode} for canton {$canton} is valid on {$date}.",
            invalidParams: [$field => ["Missing withholding tax tariff {$canton}/{$tariffCode} valid on {$date}."]],
            title: 'Missing Withholding Tax Tariff',
        );
    }

    public static function noBracket(string $canton, string $tariffCode, string $taxableIncome, ?string $employeeId = null): self
    {
        $field = $employeeId !== null ? "employees.{$employeeId}.qst_tariff_code" : 'qst_tariff_code';

        return new self(
            detail: "Withholding tax tariff {$canton}/{$tariffCode} has no bracket for a taxable income of CHF {$taxableIncome}.",
            invalidParams: [$field => ["No tariff bracket covers CHF {$taxableIncome}."]],
            title: 'Missing Withholding Tax Tariff',
        );
    }
}
