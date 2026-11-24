<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation;

/**
 * The calculated payslip and its lines; identifiers and run are assigned by the caller.
 */
final readonly class PayrollResult
{
    /**
     * @param  list<PayslipLineData>  $lines
     */
    public function __construct(public PayslipData $payslip, public array $lines) {}

    /**
     * The total of the lines with a code.
     */
    public function amount(string $code): string
    {
        return Dec::sum(array_map(
            static fn (PayslipLineData $line): string => $line->amount,
            $this->linesWithCode($code),
        ));
    }

    /**
     * @return list<PayslipLineData>
     */
    public function linesWithCode(string $code): array
    {
        return array_values(array_filter($this->lines, static fn (PayslipLineData $line): bool => $line->wageTypeCode === $code));
    }
}
