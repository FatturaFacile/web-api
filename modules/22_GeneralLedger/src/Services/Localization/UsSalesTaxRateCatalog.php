<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Localization;

/**
 * Seed set of United States states for the sales tax preload: the base state rate and the three-digit ZIP prefixes the
 * state covers (used by the rate lookup to find the state of a ZIP code). The rates are the state-level rates in force
 * at the effective date below; county, city and district rates are not in the seed and are added by the tenant. Rates
 * change: the tenant must verify them before relying on them for filing.
 */
final class UsSalesTaxRateCatalog
{
    public const string EffectiveFrom = '2025-01-01';

    /**
     * @return array<string, array{name: string, rate: string, zip_ranges: list<array{int, int}>}>
     */
    public static function states(): array
    {
        return [
            'CA' => ['name' => 'California', 'rate' => '7.2500', 'zip_ranges' => [[900, 961]]],
            'NY' => ['name' => 'New York', 'rate' => '4.0000', 'zip_ranges' => [[100, 149]]],
            'TX' => ['name' => 'Texas', 'rate' => '6.2500', 'zip_ranges' => [[750, 799], [885, 885]]],
            'FL' => ['name' => 'Florida', 'rate' => '6.0000', 'zip_ranges' => [[320, 349]]],
            'WA' => ['name' => 'Washington', 'rate' => '6.5000', 'zip_ranges' => [[980, 994]]],
            'IL' => ['name' => 'Illinois', 'rate' => '6.2500', 'zip_ranges' => [[600, 629]]],
            'PA' => ['name' => 'Pennsylvania', 'rate' => '6.0000', 'zip_ranges' => [[150, 196]]],
            'OH' => ['name' => 'Ohio', 'rate' => '5.7500', 'zip_ranges' => [[430, 458]]],
            'OR' => ['name' => 'Oregon', 'rate' => '0.0000', 'zip_ranges' => [[970, 979]]],
        ];
    }

    /**
     * The state of the seed set that a ZIP code belongs to, or null when the ZIP is outside it.
     */
    public static function stateOfZip(string $zip): ?string
    {
        $prefix = (int) substr(preg_replace('/\D/', '', $zip) ?? '', 0, 3);

        foreach (self::states() as $state => $entry) {
            foreach ($entry['zip_ranges'] as [$low, $high]) {
                if ($prefix >= $low && $prefix <= $high) {
                    return $state;
                }
            }
        }

        return null;
    }
}
