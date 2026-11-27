<?php

declare(strict_types=1);

use Database\Seeders\Translations\DistributionFrontendTranslations;
use Database\Seeders\Translations\EnglishFrontendTranslations;
use Database\Seeders\Translations\FrenchFrontendTranslations;
use Database\Seeders\Translations\GermanFrontendTranslations;
use Database\Seeders\Translations\ItalianFrontendTranslations;

it('carries every distribution key in the four languages with the same placeholders', function (): void {
    $definitions = DistributionFrontendTranslations::definitions();
    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $tuple) {
        expect($tuple)->toHaveCount(4);

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('', $key);
        }

        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/\{(\w+)\}/', $text, $found);
            sort($found[1]);

            return $found[1];
        }, $tuple);

        expect($placeholders[1])->toBe($placeholders[0], $key)
            ->and($placeholders[2])->toBe($placeholders[0], $key)
            ->and($placeholders[3])->toBe($placeholders[0], $key);
    }

    $keys = array_keys($definitions);

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(DistributionFrontendTranslations::all($locale)))->toBe($keys);
    }
});

it('does not repeat a key of the base dictionaries', function (): void {
    $own = array_keys(DistributionFrontendTranslations::definitions());

    foreach ([ItalianFrontendTranslations::all(), EnglishFrontendTranslations::all(), GermanFrontendTranslations::all(), FrenchFrontendTranslations::all()] as $dictionary) {
        expect(array_intersect($own, array_keys($dictionary)))->toBe([]);
    }
});

it('rejects a language the dictionary does not have', function (): void {
    DistributionFrontendTranslations::all('es');
})->throws(InvalidArgumentException::class);

it('has a label for every enum value the distribution screens show', function (): void {
    $keys = array_keys(DistributionFrontendTranslations::definitions());

    $enums = [
        'warehouseRole' => ['internal', 'retail_pos', 'transit', 'third_party_3pl', 'consignment_out', 'consignment_in'],
        'policy' => ['min_max', 'reorder_point_fixed_qty', 'periodic_review', 'demand_driven_drp'],
        'allocationMode' => ['shared_pool', 'percentage', 'fixed_reserve', 'priority_rank'],
        'proposalType' => ['replenishment', 'balancing'],
        'proposalStatus' => ['pending', 'approved', 'rejected', 'converted', 'shortfall'],
        'transportMode' => ['road', 'rail', 'air', 'sea', 'courier', 'internal'],
        'bucketType' => ['daily', 'weekly'],
        'channelType' => ['b2b_portal', 'ecommerce', 'retail_pos', 'marketplace', 'direct_sales', 'custom'],
        'settlementTrigger' => ['on_consumption', 'monthly_report', 'on_return'],
        'billingFrequency' => ['weekly', 'monthly', 'quarterly'],
        'transferStatus' => ['draft', 'approved', 'in_transit', 'partially_received', 'received', 'discrepancy_pending', 'closed', 'cancelled'],
        'resolution' => ['keep_in_transit', 'write_off', 'return_to_source', 'carrier_claim'],
        'alertType' => ['stockout_risk', 'excess_stock', 'late_inbound'],
        'drpRow' => ['gross_requirements', 'scheduled_receipts', 'projected_available', 'net_requirements', 'planned_receipts', 'planned_releases'],
        'direction' => ['out', 'in'],
        'settlementStatus' => ['open', 'invoiced'],
        'reconciliationStatus' => ['balanced', 'discrepancy'],
    ];

    foreach ($enums as $group => $values) {
        foreach ($values as $value) {
            expect($keys)->toContain("distribution.{$group}.{$value}");
        }
    }
});

it('names every page of the distribution menu', function (): void {
    $keys = array_keys(DistributionFrontendTranslations::definitions());

    foreach (['OVERVIEW', 'NETWORK', 'TRANSFERS', 'IN_TRANSIT', 'REPLENISHMENT', 'PROPOSALS', 'DRP', 'CONSIGNMENT', 'CHANNELS', 'ATP'] as $page) {
        expect($keys)->toContain("NAV.DISTRIBUTION_{$page}")->toContain("PAGE_TITLE.DISTRIBUTION_{$page}");
    }

    expect($keys)->toContain('NAV.DISTRIBUTION');
});
