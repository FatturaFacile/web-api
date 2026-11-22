<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Domain\Enums;

/**
 * Recipe families with their default optimal bands. Every band is [min, max] of the index per 1,000 g of mix (POD, PAC), of
 * percent of the mix (fats, SLNG, total solids) or of degrees Celsius (serving temperature). Tenants may override a band in the
 * request of the preview; these are the laboratory defaults.
 */
enum RecipeCategory: string
{
    case DairyCream = 'DAIRY_CREAM';
    case FruitSorbet = 'FRUIT_SORBET';
    case ChocolateFatPaste = 'CHOCOLATE_FAT_PASTE';
    case SavoryGastronomic = 'SAVORY_GASTRONOMIC';
    case SemifreddoColdPastry = 'SEMIFREDDO_COLD_PASTRY';
    case GanacheChocolate = 'GANACHE_CHOCOLATE';

    /**
     * @return array<string, array{0: float, 1: float}>
     */
    public function bands(): array
    {
        return match ($this) {
            self::DairyCream => ['pod' => [160, 220], 'pac' => [240, 280], 'milk_fat' => [6, 10], 'total_fat' => [6, 12], 'slng' => [8, 11], 'total_solids' => [36, 42], 'serving_temp' => [-14, -11]],
            self::FruitSorbet => ['pod' => [250, 320], 'pac' => [290, 340], 'milk_fat' => [0, 1], 'total_fat' => [0, 1], 'slng' => [0, 1], 'total_solids' => [29, 36], 'serving_temp' => [-15, -12]],
            self::ChocolateFatPaste => ['pod' => [180, 240], 'pac' => [240, 290], 'milk_fat' => [2, 6], 'total_fat' => [8, 16], 'slng' => [6, 10], 'total_solids' => [40, 46], 'serving_temp' => [-14, -11]],
            self::SavoryGastronomic => ['pod' => [40, 120], 'pac' => [150, 250], 'milk_fat' => [4, 9], 'total_fat' => [5, 12], 'slng' => [6, 11], 'total_solids' => [30, 42], 'serving_temp' => [-13, -10]],
            self::SemifreddoColdPastry => ['pod' => [180, 260], 'pac' => [220, 290], 'milk_fat' => [8, 14], 'total_fat' => [10, 18], 'slng' => [6, 10], 'total_solids' => [38, 48], 'serving_temp' => [-14, -10]],
            self::GanacheChocolate => ['pod' => [200, 450], 'pac' => [150, 400], 'milk_fat' => [5, 20], 'total_fat' => [15, 35], 'slng' => [0, 6], 'total_solids' => [60, 80], 'serving_temp' => [16, 22]],
        ];
    }

    /**
     * Share of the freezable water that is ice at the serving temperature (drives the ideal scoopability temperature).
     */
    public function targetIceFraction(): float
    {
        return match ($this) {
            self::FruitSorbet => 0.78,
            self::SemifreddoColdPastry => 0.82,
            default => 0.80,
        };
    }

    public function isFrozen(): bool
    {
        return $this !== self::GanacheChocolate;
    }
}
