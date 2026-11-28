<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\TimeTravelFrontendTranslations;

it('defines every label in the four locales', function (): void {
    $definitions = TimeTravelFrontendTranslations::definitions();

    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $tuple) {
        expect($tuple)->toHaveCount(4, $key);

        foreach ($tuple as $text) {
            expect($text)->toBeString()->not->toBe('', $key);
        }
    }
});

it('keeps the same placeholders in every language', function (): void {
    foreach (TimeTravelFrontendTranslations::definitions() as $key => $tuple) {
        $sets = array_map(function (string $text): array {
            preg_match_all('/\{(\w+)\}/', $text, $matches);
            sort($matches[1]);

            return $matches[1];
        }, $tuple);

        expect(array_unique($sets, SORT_REGULAR))->toHaveCount(1, "placeholders of {$key}");
    }
});

it('namespaces every key under TIME_TRAVEL', function (): void {
    foreach (array_keys(TimeTravelFrontendTranslations::definitions()) as $key) {
        expect($key)->toStartWith('TIME_TRAVEL.');
    }
});

it('serves every code the API can report', function (): void {
    $keys = array_keys(TimeTravelFrontendTranslations::all('en'));

    $required = [
        ...array_map(fn (string $bucket): string => 'TIME_TRAVEL.BUCKET.'.$bucket, ['CURRENT', '1_30', '31_60', '61_90', '90_PLUS']),
        ...array_map(fn (string $aggregate): string => 'TIME_TRAVEL.AGGREGATE.'.$aggregate, ['GENERAL_LEDGER', 'OPEN_ITEMS', 'INVENTORY_WMS', 'FIXED_ASSETS']),
        ...array_map(fn (string $method): string => 'TIME_TRAVEL.METHOD.'.$method, ['WAC', 'FIFO', 'LIFO']),
        ...array_map(fn (string $partner): string => 'TIME_TRAVEL.PARTNER.'.$partner, ['CUSTOMER', 'SUPPLIER']),
        ...array_map(fn (string $status): string => 'TIME_TRAVEL.DIFF.STATUS_'.$status, ['UNCHANGED', 'DELTA', 'BACKDATED']),
        ...array_map(fn (string $preset): string => 'TIME_TRAVEL.PRESET.'.$preset, ['END_OF_LAST_QUARTER', 'FISCAL_YEAR_CLOSE', 'AUDIT_VS_TODAY']),
    ];

    expect(array_values(array_diff($required, $keys)))->toBe([]);
});

it('registers the dictionary in the frontend translation seeder of every locale', function (): void {
    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $dictionary = FrontendTranslationSeeder::dictionary($locale);

        foreach (TimeTravelFrontendTranslations::all($locale) as $key => $text) {
            expect($dictionary)->toHaveKey($key);
            expect($dictionary[$key])->toBe($text);
        }
    }
});
