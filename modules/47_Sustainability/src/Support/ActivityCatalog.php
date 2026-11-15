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
        'capital_goods' => ['scope' => 3, 'category' => 'capital_goods', 'units' => ['EUR', 'CHF']],
        'fuel_energy_related' => ['scope' => 3, 'category' => 'fuel_energy_related', 'units' => ['kWh']],
        'freight_road' => ['scope' => 3, 'category' => 'upstream_transport', 'units' => ['tkm']],
        'freight_sea' => ['scope' => 3, 'category' => 'upstream_transport', 'units' => ['tkm']],
        'freight_air' => ['scope' => 3, 'category' => 'upstream_transport', 'units' => ['tkm']],
        'employee_commuting_car' => ['scope' => 3, 'category' => 'employee_commuting', 'units' => ['km']],
        'employee_commuting_transit' => ['scope' => 3, 'category' => 'employee_commuting', 'units' => ['km']],
        'upstream_leased_assets' => ['scope' => 3, 'category' => 'upstream_leased_assets', 'units' => ['kWh']],
        'downstream_freight' => ['scope' => 3, 'category' => 'downstream_transport', 'units' => ['tkm']],
        'processing_sold_products' => ['scope' => 3, 'category' => 'processing_sold_products', 'units' => ['kg']],
        'use_of_sold_products' => ['scope' => 3, 'category' => 'use_of_sold_products', 'units' => ['kWh']],
        'end_of_life_sold_products' => ['scope' => 3, 'category' => 'end_of_life_sold_products', 'units' => ['kg']],
        'downstream_leased_assets' => ['scope' => 3, 'category' => 'downstream_leased_assets', 'units' => ['kWh']],
        'franchises' => ['scope' => 3, 'category' => 'franchises', 'units' => ['kWh']],
        'investments' => ['scope' => 3, 'category' => 'investments', 'units' => ['EUR', 'CHF']],
    ];

    /**
     * The 15 GHG Protocol Scope 3 categories: number to the reporting category stored on factors and activity records.
     *
     * @var array<int, string>
     */
    public const array Scope3Categories = [
        1 => 'purchased_goods_services',
        2 => 'capital_goods',
        3 => 'fuel_energy_related',
        4 => 'upstream_transport',
        5 => 'waste_generated',
        6 => 'business_travel',
        7 => 'employee_commuting',
        8 => 'upstream_leased_assets',
        9 => 'downstream_transport',
        10 => 'processing_sold_products',
        11 => 'use_of_sold_products',
        12 => 'end_of_life_sold_products',
        13 => 'downstream_leased_assets',
        14 => 'franchises',
        15 => 'investments',
    ];

    /** Recorded categories that are reported inside a numbered Scope 3 category. */
    public const array Scope3CategoryAliases = ['water_supply_treatment' => 1];

    public static function scope3NumberOf(string $category): ?int
    {
        $number = array_search($category, self::Scope3Categories, true);

        return $number === false ? (self::Scope3CategoryAliases[$category] ?? null) : $number;
    }

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
