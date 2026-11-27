<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\ObservabilityFrontendTranslations;
use Modules\Observability\Connectors\ConnectorCatalog;

it('defines every label in the four locales', function (): void {
    $definitions = ObservabilityFrontendTranslations::definitions();

    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $tuple) {
        expect($tuple)->toHaveCount(4, $key);

        foreach ($tuple as $text) {
            expect($text)->toBeString()->not->toBe('', $key);
        }
    }
});

it('keeps the same placeholders in every language', function (): void {
    foreach (ObservabilityFrontendTranslations::definitions() as $key => $tuple) {
        $sets = array_map(function (string $text): array {
            preg_match_all('/\{(\w+)\}/', $text, $matches);
            sort($matches[1]);

            return $matches[1];
        }, $tuple);

        expect(array_unique($sets, SORT_REGULAR))->toHaveCount(1, "placeholders of {$key}");
    }
});

it('serves every status, group, connector and plan state the API can report', function (): void {
    $keys = array_keys(ObservabilityFrontendTranslations::all('en'));

    $required = [
        ...array_map(fn (string $status): string => 'OBSERVABILITY.STATUS.'.$status, ['OPERATIONAL', 'DEGRADED', 'OUTAGE', 'MAINTENANCE', 'UNKNOWN']),
        ...array_map(fn (string $group): string => 'OBSERVABILITY.GROUP.'.$group, ['FISCAL', 'REGISTRY', 'RATES', 'PAYMENTS']),
        ...array_map(fn (string $plan): string => 'OBSERVABILITY.PLAN_STATUS.'.$plan, ['AVAILABLE', 'UNSUPPORTED', 'SKIPPED', 'FAILED']),
        ...array_map(fn (string $reason): string => 'OBSERVABILITY.STATEMENTS.REASON_'.$reason, ['NOT_POSTGRES', 'EXTENSION_MISSING', 'PERMISSION_DENIED']),
    ];

    foreach (app(ConnectorCatalog::class)->all() as $connector) {
        $required[] = $connector['name_code'];
    }

    expect(array_values(array_diff($required, $keys)))->toBe([]);
});

it('registers the labels in the API-served frontend dictionary of each locale without overlapping another module', function (): void {
    foreach (['it' => 0, 'en' => 1, 'de' => 2, 'fr' => 3] as $locale => $index) {
        $dictionary = FrontendTranslationSeeder::dictionary($locale);

        foreach (ObservabilityFrontendTranslations::definitions() as $key => $tuple) {
            expect($dictionary[$key] ?? null)->toBe($tuple[$index], "{$locale} {$key}");
        }
    }
});

it('keeps the labels of the other modules out of the observability seeder', function (): void {
    foreach (array_keys(ObservabilityFrontendTranslations::definitions()) as $key) {
        expect($key)->toMatch('/^(OBSERVABILITY|NAV|PAGE_TITLE)\./');
    }
});
