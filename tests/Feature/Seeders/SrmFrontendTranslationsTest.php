<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\SrmFrontendTranslations;
use Database\Seeders\TranslationSeeder;

it('translates every SRM key into the four languages', function (): void {
    foreach (SrmFrontendTranslations::definitions() as $key => $texts) {
        expect($texts)->toHaveCount(4, $key);

        foreach ($texts as $text) {
            expect(trim($text))->not->toBe('', $key);
        }
    }
});

it('covers the statuses, tiers, rating components and document types the SRM screens use', function (): void {
    $keys = array_keys(SrmFrontendTranslations::definitions());

    $required = [
        ...array_map(static fn (string $tier): string => "srm.tier.{$tier}", ['A', 'B', 'C', 'D']),
        ...array_map(static fn (string $component): string => "srm.component.{$component}", ['on_time_delivery_rate', 'quality_acceptance_rate', 'price_competitiveness_score', 'invoice_accuracy_rate', 'rfq_responsiveness_rate']),
        ...array_map(static fn (string $type): string => "srm.flow.type.{$type}", ['requisition', 'rfq', 'framework_agreement', 'purchase_order', 'goods_receipt', 'supplier_invoice', 'purchase_return']),
        ...array_map(static fn (string $status): string => "srm.qualification.{$status}", ['prospect', 'in_qualification', 'qualified', 'conditional', 'preferred', 'expired', 'suspended', 'disqualified', 'blacklisted']),
        ...array_map(static fn (string $status): string => "srm.flow.status.{$status}", ['pending_approval', 'approved', 'received', 'blocked_discrepancy', 'matched', 'override_approved']),
        'NAV.PROCUREMENT_SRM',
        'NAV.PROCUREMENT_SRM_SCORECARDS',
        'PAGE_TITLE.PROCUREMENT_SRM',
        'PAGE_TITLE.PROCUREMENT_SRM_SCORECARDS',
    ];

    expect(array_diff($required, $keys))->toBe([]);
});

it('registers the SRM dictionary for every seeded locale', function (): void {
    foreach (TranslationSeeder::LOCALES as $locale) {
        $dictionary = FrontendTranslationSeeder::dictionary($locale);

        expect($dictionary)->toHaveKey('srm.dashboard.title')
            ->and($dictionary)->toHaveKey('NAV.PROCUREMENT_SRM');
    }
});

it('keeps the placeholders of the four languages identical', function (): void {
    foreach (SrmFrontendTranslations::definitions() as $key => $texts) {
        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/\{\w+}/', $text, $matches);
            sort($matches[0]);

            return $matches[0];
        }, $texts);

        expect(array_unique(array_map('json_encode', $placeholders)))->toHaveCount(1, $key);
    }
});
