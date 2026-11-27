<?php

declare(strict_types=1);

namespace Modules\DownPayments\Domain\Enums;

/** How the amount of an installment is stated. */
enum CalculationMode: string
{
    case Percentage = 'percentage';
    case FixedNet = 'fixed_net';
    case FixedGross = 'fixed_gross';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
