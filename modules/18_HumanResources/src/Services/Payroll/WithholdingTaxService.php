<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Payroll;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\HumanResources\Contracts\Payroll\WithholdingTaxServiceInterface;
use Modules\HumanResources\DTOs\Payroll\WithholdingTariffData;
use Modules\HumanResources\Exceptions\Payroll\MissingWithholdingTariffException;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;

/**
 * Withholding tax (Quellensteuer) tariff lookup by canton, tariff code and date. Tenant tariffs override the
 * system defaults; the church tax flag of the payroll profile selects the Y/N variant of the tariff code.
 */
final class WithholdingTaxService implements WithholdingTaxServiceInterface
{
    public function effectiveTariffCode(string $tariffCode, bool $churchTax): string
    {
        $code = strtoupper(trim($tariffCode));

        if (preg_match('/^([A-Z]\d{1,2})[YN]$/', $code, $matches) === 1) {
            return $matches[1].($churchTax ? 'Y' : 'N');
        }

        return $code;
    }

    public function resolveTariff(string $canton, string $tariffCode, bool $churchTax, DateTimeInterface $date, ?string $employeeId = null): WithholdingTariffData
    {
        $canton = strtoupper($canton);
        $effectiveCode = $this->effectiveTariffCode($tariffCode, $churchTax);
        $day = $date->format('Y-m-d');

        $candidates = WithholdingTariff::query()
            ->with('brackets')
            ->where('canton', $canton)
            ->where('tariff_code', $effectiveCode)
            ->whereDate('valid_from', '<=', $day)
            ->where(function (Builder $query) use ($day): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day);
            })
            ->orderByDesc('valid_from')
            ->get();

        $tariff = $candidates->first(fn (WithholdingTariff $candidate): bool => $candidate->tenant_id !== null)
            ?? $candidates->first();

        if ($tariff === null || $tariff->brackets->isEmpty()) {
            throw MissingWithholdingTariffException::for($canton, $effectiveCode, $day, $employeeId);
        }

        return WithholdingTariffData::fromModel($tariff);
    }

    public function taxFor(WithholdingTariffData $tariff, int $taxableIncomeRappen, ?string $employeeId = null): int
    {
        return self::computeTax($tariff, $taxableIncomeRappen, $employeeId);
    }

    /**
     * Pure tax computation shared with the calculator: bracket rate applied on the whole taxable income plus
     * the bracket's fixed amount, rounded to 0.05 CHF.
     *
     * @throws MissingWithholdingTariffException when no bracket covers the income
     */
    public static function computeTax(WithholdingTariffData $tariff, int $taxableIncomeRappen, ?string $employeeId = null): int
    {
        if ($taxableIncomeRappen <= 0) {
            return 0;
        }

        $bracket = $tariff->bracketFor($taxableIncomeRappen);

        if ($bracket === null) {
            throw MissingWithholdingTariffException::noBracket(
                $tariff->canton,
                $tariff->tariffCode,
                Rappen::toDecimal($taxableIncomeRappen),
                $employeeId,
            );
        }

        return RoundingPolicy::roundToFiveRappen(
            RoundingPolicy::applyRate($taxableIncomeRappen, $bracket['rate_ppm']) + $bracket['fixed_amount_rappen'],
        );
    }
}
