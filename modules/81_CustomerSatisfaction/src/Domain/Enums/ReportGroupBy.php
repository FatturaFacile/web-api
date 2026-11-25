<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Domain\Enums;

use Modules\CustomerSatisfaction\Domain\Enums\Concerns\EnumValues;

enum ReportGroupBy: string
{
    use EnumValues;

    case Overall = 'overall';
    case Department = 'department';
    case Technician = 'technician';
    case ProductLine = 'product_line';
    case Branch = 'branch';

    /**
     * Column of survey_responses holding the dimension (null for the overall view).
     */
    public function column(): ?string
    {
        return match ($this) {
            self::Overall => null,
            self::Department => 'department_code',
            self::Technician => 'technician_id',
            self::ProductLine => 'product_line_code',
            self::Branch => 'branch_code',
        };
    }
}
