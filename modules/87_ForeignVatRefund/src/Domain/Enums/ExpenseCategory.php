<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Domain\Enums;

/**
 * The standard goods and services codes (1-10) of the EU refund application.
 */
enum ExpenseCategory: int
{
    case Fuel = 1;
    case HiringOfMeansOfTransport = 2;
    case ExpenditureOnMeansOfTransport = 3;
    case RoadTolls = 4;
    case TravelExpenses = 5;
    case Accommodation = 6;
    case FoodDrinkRestaurant = 7;
    case FairsAndExhibitions = 8;
    case LuxuryEntertainment = 9;
    case Other = 10;

    /**
     * @return list<int>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): int => $case->value, self::cases());
    }
}
