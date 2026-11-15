<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs\Payroll;

use Modules\HumanResources\Enums\Payroll\PayslipLineSide;
use Modules\HumanResources\Enums\Payroll\PayslipLineType;

final readonly class PayslipLineData
{
    public function __construct(
        public string $code,
        public string $label,
        public PayslipLineType $lineType,
        public PayslipLineSide $side,
        public int $baseRappen,
        public ?int $ratePpm,
        public int $amountRappen,
        public ?string $wageTypeId = null,
    ) {}

    public function isEmployeeDeduction(): bool
    {
        return $this->side === PayslipLineSide::Employee && $this->lineType !== PayslipLineType::Earning;
    }

    public function isEmployerContribution(): bool
    {
        return $this->side === PayslipLineSide::Employer;
    }

    /**
     * @return array{code: string, label: string, line_type: string, side: string, base_rappen: int, rate_ppm: int|null, amount_rappen: int, wage_type_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'line_type' => $this->lineType->value,
            'side' => $this->side->value,
            'base_rappen' => $this->baseRappen,
            'rate_ppm' => $this->ratePpm,
            'amount_rappen' => $this->amountRappen,
            'wage_type_id' => $this->wageTypeId,
        ];
    }
}
