<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

enum BillingModel: string
{
    case PerPieceWashed = 'per_piece_washed';
    case PerKgDirtyOrClean = 'per_kg_dirty_or_clean';
    case FixedMonthlyRentalPerGarment = 'fixed_monthly_rental_per_garment';
    case HybridRentalPlusWash = 'hybrid_rental_plus_wash';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function billsWashPerPiece(): bool
    {
        return $this === self::PerPieceWashed || $this === self::HybridRentalPlusWash;
    }

    public function billsWashPerKg(): bool
    {
        return $this === self::PerKgDirtyOrClean;
    }

    public function billsRental(): bool
    {
        return $this === self::FixedMonthlyRentalPerGarment || $this === self::HybridRentalPlusWash;
    }
}
