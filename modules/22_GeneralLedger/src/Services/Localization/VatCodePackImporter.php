<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Localization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Loads the VAT codes of a country pack as generic rows (no tenant, shared by every tenant). Idempotent: a code that
 * exists is updated in place, never duplicated, and a deactivated code stays deactivated.
 */
final class VatCodePackImporter
{
    /**
     * @return list<array{country: string, name: string, codes: int}>
     */
    public function packs(): array
    {
        return array_values(array_map(
            static fn (array $pack): array => ['country' => $pack['country'], 'name' => $pack['name'], 'codes' => count($pack['codes'])],
            VatCodePackCatalog::all(),
        ));
    }

    /**
     * @return array{country: string, created: int, updated: int}
     *
     * @throws InvalidArgumentException when the country has no pack
     */
    public function import(string $country): array
    {
        $pack = VatCodePackCatalog::all()[strtoupper($country)] ?? throw new InvalidArgumentException("Unknown VAT code pack [{$country}]");

        return DB::transaction(function () use ($pack): array {
            $created = 0;
            $updated = 0;

            foreach ($pack['codes'] as $entry) {
                $values = [
                    'country_iso' => $pack['country'],
                    'country' => $pack['country'],
                    'rate' => $entry['rate'],
                    'tax_rate_percent' => $entry['rate'],
                    'nature' => $entry['nature'],
                    'description' => $entry['description'],
                    'is_reverse_charge' => $entry['reverse_charge'],
                    'is_split_payment' => false,
                    'updated_at' => now(),
                ];

                if (DB::table('vat_codes')->where('code', $entry['code'])->exists()) {
                    DB::table('vat_codes')->where('code', $entry['code'])->update($values);
                    $updated++;

                    continue;
                }

                DB::table('vat_codes')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => null,
                    'company_id' => null,
                    'code' => $entry['code'],
                    'is_active' => true,
                    'created_at' => now(),
                    ...$values,
                ]);
                $created++;
            }

            return ['country' => $pack['country'], 'created' => $created, 'updated' => $updated];
        });
    }
}
