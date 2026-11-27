<?php

declare(strict_types=1);

namespace Modules\DownPayments\Domain\Enums;

/** The three legal paths a held deposit can take. */
enum DepositResolution: string
{
    case ImputeToPrice = 'impute_to_price';
    case ForfeitBreach = 'forfeit_breach';
    case Refund = 'refund';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
