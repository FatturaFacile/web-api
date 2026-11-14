<?php

declare(strict_types=1);

namespace Modules\Sustainability\Support;

/**
 * The activity types of the inventory with the GHG Protocol scope, the reporting category and the units they are
 * measured in. The scope and the category of a factor and of an activity record always come from here.
 */
final class ActivityCatalog
{
    public const string Electricity = 'electricity_grid';

    /**
     * @var array<string, array{scope: int, category: string, units: list<string>}>
     */
    public const array Types = [
        'electricity_grid' => ['scope' => 2, 'category' => 'purchased_electricity', 'units' => ['kWh']],
        'natural_gas' => ['scope' => 1, 'category' => 'stationary_combustion', 'units' => ['m3']],
        'fuel_diesel' => ['scope' => 1, 'category' => 'mobile_combustion', 'units' => ['l']],
        'fuel_petrol' => ['scope' => 1, 'category' => 'mobile_combustion', 'units' => ['l']],
        'business_travel_car' => ['scope' => 3, 'category' => 'business_travel', 'units' => ['km']],
        'business_travel_air' => ['scope' => 3, 'category' => 'business_travel', 'units' => ['km']],
        'business_travel_rail' => ['scope' => 3, 'category' => 'business_travel', 'units' => ['km']],
        'purchased_goods' => ['scope' => 3, 'category' => 'purchased_goods_services', 'units' => ['EUR', 'CHF']],
        'waste' => ['scope' => 3, 'category' => 'waste_generated', 'units' => ['kg']],
        'water' => ['scope' => 3, 'category' => 'water_supply_treatment', 'units' => ['m3']],
    ];

    /** @var list<string> */
    public const array Regions = ['IT', 'CH', 'EU', 'GLOBAL'];

    /** @var list<string> */
    public const array DataQualities = ['measured', 'estimated'];

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::Types);
    }

    /**
     * @return list<string>
     */
    public static function units(): array
    {
        return array_values(array_unique(array_merge(...array_column(self::Types, 'units'))));
    }

    public static function allows(string $activityType, string $unit): bool
    {
        return in_array($unit, self::Types[$activityType]['units'] ?? [], true);
    }

    public static function scopeOf(string $activityType): int
    {
        return self::Types[$activityType]['scope'];
    }

    public static function categoryOf(string $activityType): string
    {
        return self::Types[$activityType]['category'];
    }
}
