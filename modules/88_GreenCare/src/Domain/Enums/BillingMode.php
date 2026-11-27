<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Enums;

/**
 * How a seasonal maintenance contract is invoiced.
 */
enum BillingMode: string
{
    case FixedInstallments = 'fixed_installments';
    case PerPassage = 'per_passage';
    case TimeAndMaterial = 'time_and_material';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
