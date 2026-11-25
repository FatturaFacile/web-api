<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

enum BillingLineKind: string
{
    case WashPiece = 'wash_piece';
    case WashKg = 'wash_kg';
    case MinimumTopUp = 'minimum_top_up';
    case Rental = 'rental';
    case Unreturned = 'unreturned';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
