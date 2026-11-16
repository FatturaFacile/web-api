<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs\Payroll;

use Modules\HumanResources\Enums\Payroll\WageTypeCategory;
use Modules\HumanResources\Models\Payroll\WageType;

/**
 * Wage type definition as seen by the calculator (subjection flags drive every insurance base).
 */
final readonly class WageTypeData
{
    public function __construct(
        public ?string $id,
        public string $code,
        public string $name,
        public WageTypeCategory $category,
        public bool $isAhvSubject,
        public bool $isAlvSubject,
        public bool $isBvgSubject,
        public bool $isUvgSubject,
        public bool $isQstSubject,
        public ?string $elmCode = null,
    ) {}

    public static function fromModel(WageType $wageType): self
    {
        return new self(
            id: (string) $wageType->id,
            code: $wageType->code,
            name: $wageType->name,
            category: $wageType->category,
            isAhvSubject: $wageType->is_ahv_subject,
            isAlvSubject: $wageType->is_alv_subject,
            isBvgSubject: $wageType->is_bvg_subject,
            isUvgSubject: $wageType->is_uvg_subject,
            isQstSubject: $wageType->is_qst_subject,
            elmCode: $wageType->elm_code,
        );
    }
}
