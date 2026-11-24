<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation;

/**
 * A wage type: which bases a line contributes to and where it goes on the salary certificate.
 */
final readonly class WageTypeData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $kind = WageTypeKind::Earning,
        public bool $subjectToAhv = false,
        public bool $subjectToAlv = false,
        public bool $subjectToUvg = false,
        public bool $subjectToKtg = false,
        public bool $subjectToBvg = false,
        public bool $subjectToWithholdingTax = false,
        public bool $isIrregular = false,
        public ?string $certificateBox = null,
        public int $sortOrder = 0,
        public bool $isSystem = false,
    ) {}
}
