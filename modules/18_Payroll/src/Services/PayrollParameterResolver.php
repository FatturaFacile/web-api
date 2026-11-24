<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use Modules\Payroll\Calculation\Italian\ItalianPayrollParameters;
use Modules\Payroll\Calculation\Swiss\SwissPayrollParameters;
use Modules\Payroll\Models\PayrollAnnualParameter;

/**
 * Finds the legal parameters of a year: the row of the year or, when the table has not been updated yet, the closest
 * earlier one with a warning on every payslip. A company row wins over the generic row of the same year.
 */
final class PayrollParameterResolver
{
    /**
     * @return array{0: SwissPayrollParameters, 1: list<string>}
     */
    public function swiss(int $year): array
    {
        $row = $this->row('CH', $year);
        $parameters = $row === null ? new SwissPayrollParameters : SwissPayrollParameters::fromArray($row->year, $row->parameters);

        return [$parameters, $this->warnings($parameters->year, $year)];
    }

    /**
     * @return array{0: ItalianPayrollParameters, 1: list<string>}
     */
    public function italian(int $year): array
    {
        $row = $this->row('IT', $year);
        $parameters = $row === null ? new ItalianPayrollParameters : ItalianPayrollParameters::fromArray($row->year, $row->parameters);

        return [$parameters, $this->warnings($parameters->year, $year)];
    }

    private function row(string $country, int $year): ?PayrollAnnualParameter
    {
        $rows = PayrollAnnualParameter::query()->where('country', $country)->get();

        return $rows->where('year', '<=', $year)->sort(self::newestCompanyFirst(...))->first()
            ?? $rows->sort(self::newestCompanyFirst(...))->last();
    }

    private static function newestCompanyFirst(PayrollAnnualParameter $left, PayrollAnnualParameter $right): int
    {
        return [$right->year, $left->isGeneric() ? 1 : 0] <=> [$left->year, $right->isGeneric() ? 1 : 0];
    }

    /**
     * @return list<string>
     */
    private function warnings(int $parametersYear, int $year): array
    {
        return $parametersYear === $year
            ? []
            : ["Parametri legali {$parametersYear} usati per il {$year}: verificare e aggiornare i parametri annuali."];
    }
}
