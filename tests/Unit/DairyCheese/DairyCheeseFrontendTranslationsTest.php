<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\DairyCheeseFrontendTranslations;

it('has the four languages for every dairy key and no empty text', function (): void {
    $definitions = DairyCheeseFrontendTranslations::definitions();
    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $tuple) {
        expect($tuple, $key)->toHaveCount(4);

        foreach ($tuple as $text) {
            expect(trim($text), $key)->not->toBe('');
        }
    }

    $keys = array_keys($definitions);

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(DairyCheeseFrontendTranslations::all($locale)))->toBe($keys);
    }
});

it('rejects an unsupported locale', function (): void {
    DairyCheeseFrontendTranslations::all('es');
})->throws(InvalidArgumentException::class);

it('keeps every key in the dairy namespace so it never overlaps another module', function (): void {
    foreach (array_keys(DairyCheeseFrontendTranslations::definitions()) as $key) {
        expect(str_starts_with($key, 'DAIRY.') || str_starts_with($key, 'NAV.DAIRY_CHEESE') || str_starts_with($key, 'PAGE_TITLE.DAIRY_'))->toBeTrue($key);
    }
});

it('is part of the frontend dictionary of every language', function (): void {
    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $dictionary = FrontendTranslationSeeder::dictionary($locale);

        foreach (DairyCheeseFrontendTranslations::all($locale) as $key => $text) {
            expect($dictionary[$key] ?? null, $key)->toBe($text);
        }
    }
});

it('uses the same placeholders in every language', function (): void {
    $placeholders = static function (string $text): array {
        preg_match_all('/\{(\w+)\}/', $text, $matches);
        sort($matches[1]);

        return $matches[1];
    };

    foreach (DairyCheeseFrontendTranslations::definitions() as $key => $tuple) {
        $expected = $placeholders($tuple[0]);

        foreach ([1, 2, 3] as $index) {
            expect($placeholders($tuple[$index]), $key)->toBe($expected);
        }
    }
});

it('has a label for every value of every enumeration the dairy API reports', function (): void {
    $keys = array_keys(DairyCheeseFrontendTranslations::definitions());
    $groups = [
        'SESSION' => ['morning', 'evening'],
        'TANK_STATUS' => ['active', 'cleaning', 'out_of_service'],
        'JURISDICTION' => ['CH', 'IT'],
        'DELIVERY_STATUS' => ['registered', 'quarantined', 'accepted', 'rejected'],
        'INHIBITOR_RESULT' => ['negative', 'positive', 'not_tested'],
        'SETTLEMENT_STATUS' => ['draft', 'approved', 'invoiced', 'paid'],
        'VAT_BATCH_STATUS' => ['open', 'in_production', 'completed', 'aging', 'closed'],
        'WHEEL_STATUS' => ['brine', 'aging', 'sold', 'recalled'],
        'WHEEL_GRADE' => ['ungraded', 'first_category', 'second_category', 'discard'],
        'CARE_TYPE' => ['brining', 'turning', 'brushing', 'rind_washing', 'piercing', 'expert_grading'],
        'CARE_STATUS' => ['scheduled', 'done', 'skipped'],
        'CARE_SCOPE' => ['wheel', 'shelf', 'rack', 'batch'],
        'AUTHORITY' => ['dbmilch', 'sian'],
        'SUBMISSION_STATUS' => ['generated', 'submitted', 'failed'],
        'CHEESE_TYPE' => ['parmigiano_reggiano_dop', 'grana_padano_dop', 'gruyere_aop', 'sbrinz_aop', 'alpe_ticinese_dop', 'generic'],
        'PRICING_COMPONENT_TYPE' => ['fat', 'protein', 'tbc', 'scc', 'inhibitor', 'freezing_point', 'casein', 'urea', 'cheese_milk_supplement', 'dop_premium'],
        'IMPORT_FORMAT' => ['suisselab', 'tsm', 'aia', 'aral', 'generic_csv'],
        'DOCUMENT_TYPE' => ['self_billing', 'credit_note', 'invoice'],
        'TRACE_NODE' => ['farmer', 'delivery', 'test', 'tank', 'batch', 'wheel', 'customer'],
        'TRACE_SOURCE' => ['delivery', 'test', 'batch', 'wheel'],
        'TRACE_DIRECTION' => ['forward', 'backward'],
        'DEADLINE_STATUS' => ['pending', 'generated', 'submitted', 'overdue'],
    ];

    foreach ($groups as $group => $values) {
        foreach ($values as $value) {
            expect($keys)->toContain('DAIRY.ENUM.'.$group.'.'.strtoupper($value));
        }
    }
});

it('has a text for every quality flag, error code and settlement line the UI translates', function (): void {
    $keys = array_keys(DairyCheeseFrontendTranslations::definitions());

    $expected = [
        ...array_map(static fn (string $code): string => 'DAIRY.FLAG.'.strtoupper($code), ['inhibitor_positive', 'scc_high', 'tbc_high', 'freezing_point_anomaly']),
        ...array_map(static fn (string $code): string => 'DAIRY.ERROR.'.strtoupper($code), [
            'generic', 'validation', 'forbidden', 'not_found', 'tank_insufficient_volume', 'delivery_settled', 'period_invalid',
            'settlement_exists', 'batch_not_open', 'plaque_range_invalid', 'wheel_not_sellable', 'import_invalid',
        ]),
        ...array_map(static fn (string $code): string => 'DAIRY.SETTLEMENT.LINE.'.strtoupper($code), [
            'base', 'fat', 'protein', 'tbc', 'scc', 'inhibitor', 'freezing_point', 'casein', 'urea', 'cheese_milk_supplement', 'dop_premium',
        ]),
    ];

    foreach ($expected as $key) {
        expect($keys)->toContain($key);
    }
});
