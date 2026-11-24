<?php

declare(strict_types=1);

namespace Modules\SwissVat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Modules\SwissVat\Models\SwissVatTaxCode;

/**
 * Resolves effective-dated Swiss VAT rates (2024+ 8.1%/2.6%/3.8%, 2018-2023 7.7%/2.5%/3.7%, exempt with/without deduction)
 * and the official AFC/ESTV Saldosteuersatz sector rate catalog.
 */
final class SwissVatRateCatalogService
{
    public const string CURRENT_EPOCH_START = '2024-01-01';

    /**
     * Official ESTV Saldosteuersatz rate bands (2024+ epoch).
     *
     * @return list<array{sector_code: string, rate_percent: string, label_key: string, afc_box: string}>
     */
    public function saldosteuersatzSectors(): array
    {
        return [
            ['sector_code' => 'SSS_01', 'rate_percent' => '0.10', 'label_key' => 'swiss_vat.sss_sectors.0_1', 'afc_box' => '323'],
            ['sector_code' => 'SSS_06', 'rate_percent' => '0.60', 'label_key' => 'swiss_vat.sss_sectors.0_6', 'afc_box' => '323'],
            ['sector_code' => 'SSS_13', 'rate_percent' => '1.30', 'label_key' => 'swiss_vat.sss_sectors.1_3', 'afc_box' => '323'],
            ['sector_code' => 'SSS_21', 'rate_percent' => '2.10', 'label_key' => 'swiss_vat.sss_sectors.2_1', 'afc_box' => '323'],
            ['sector_code' => 'SSS_30', 'rate_percent' => '3.00', 'label_key' => 'swiss_vat.sss_sectors.3_0', 'afc_box' => '323'],
            ['sector_code' => 'SSS_37', 'rate_percent' => '3.70', 'label_key' => 'swiss_vat.sss_sectors.3_7', 'afc_box' => '323'],
            ['sector_code' => 'SSS_45', 'rate_percent' => '4.50', 'label_key' => 'swiss_vat.sss_sectors.4_5', 'afc_box' => '323'],
            ['sector_code' => 'SSS_53', 'rate_percent' => '5.30', 'label_key' => 'swiss_vat.sss_sectors.5_3', 'afc_box' => '323'],
            ['sector_code' => 'SSS_62', 'rate_percent' => '6.20', 'label_key' => 'swiss_vat.sss_sectors.6_2', 'afc_box' => '323'],
            ['sector_code' => 'SSS_68', 'rate_percent' => '6.80', 'label_key' => 'swiss_vat.sss_sectors.6_8', 'afc_box' => '323'],
        ];
    }

    /**
     * Canonical statutory rate percentages for a given date.
     *
     * @return array{standard: string, reduced: string, accommodation: string, zero: string, boxes: array{standard: string, reduced: string, accommodation: string, bezugsteuer: string, sss1: string, sss2: string}}
     */
    public function ratesForDate(CarbonImmutable $date): array
    {
        if ($date->toDateString() >= self::CURRENT_EPOCH_START) {
            return [
                'standard' => '8.10',
                'reduced' => '2.60',
                'accommodation' => '3.80',
                'zero' => '0.00',
                'boxes' => [
                    'standard' => '303',
                    'reduced' => '313',
                    'accommodation' => '343',
                    'bezugsteuer' => '383',
                    'sss1' => '323',
                    'sss2' => '333',
                ],
            ];
        }

        return [
            'standard' => '7.70',
            'reduced' => '2.50',
            'accommodation' => '3.70',
            'zero' => '0.00',
            'boxes' => [
                'standard' => '302',
                'reduced' => '312',
                'accommodation' => '342',
                'bezugsteuer' => '382',
                'sss1' => '322',
                'sss2' => '332',
            ],
        ];
    }

    /**
     * Lists tax codes visible to the tenant (tenant-specific + global statutory codes), optionally filtered by effective date.
     *
     * @return Collection<int, SwissVatTaxCode>
     */
    public function listForTenant(string $tenantId, ?CarbonImmutable $onDate = null, bool $activeOnly = false): Collection
    {
        $query = SwissVatTaxCode::query()
            ->where(static function ($q) use ($tenantId): void {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        if ($onDate !== null) {
            $d = $onDate->toDateString();
            $query->whereDate('effective_from', '<=', $d)
                ->where(static function ($q) use ($d): void {
                    $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $d);
                });
        }

        return $query->orderByDesc('effective_from')->orderBy('code')->get();
    }
}
