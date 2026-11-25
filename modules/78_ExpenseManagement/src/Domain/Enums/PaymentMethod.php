<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\Enums;

enum PaymentMethod: string
{
    case EmployeeOutOfPocket = 'employee_out_of_pocket';
    case CorporateCard = 'corporate_card';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
