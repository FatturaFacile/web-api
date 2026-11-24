<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Domain\Enums;

enum RfmLabel: string
{
    case Champions = 'champions';
    case Loyal = 'loyal';
    case Promising = 'promising';
    case NewCustomers = 'new_customers';
    case AtRisk = 'at_risk';
    case Hibernating = 'hibernating';
    case Lost = 'lost';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
