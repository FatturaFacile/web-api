<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs\Payroll;

use Modules\HumanResources\Enums\Payroll\PayslipLineType;

/**
 * Output of the calculator for one employee. All amounts are integer Rappen.
 */
final readonly class PayslipResult
{
    /**
     * @param  list<PayslipLineData>  $lines
     */
    public function __construct(
        public string $employeeId,
        public int $daysWorked,
        public array $lines,
        public int $grossRappen,
        public int $ahvBaseRappen,
        public int $alvBaseRappen,
        public int $alvSolidarityBaseRappen,
        public int $bvgInsuredSalaryRappen,
        public int $uvgBaseRappen,
        public int $qstBaseRappen,
        public int $withholdingTaxRappen,
        public int $employeeDeductionsRappen,
        public int $employerContributionsRappen,
        public int $netRappen,
        public ?string $qstTariffCode = null,
        public ?string $qstCanton = null,
    ) {}

    public function line(string $code, ?string $side = null): ?PayslipLineData
    {
        foreach ($this->lines as $line) {
            if ($line->code === $code && ($side === null || $line->side->value === $side)) {
                return $line;
            }
        }

        return null;
    }

    public function amountOf(string $code, string $side): int
    {
        return $this->line($code, $side)?->amountRappen ?? 0;
    }

    /**
     * @return list<PayslipLineData>
     */
    public function earnings(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (PayslipLineData $line): bool => $line->lineType === PayslipLineType::Earning,
        ));
    }
}
