<?php

declare(strict_types=1);

use Database\Seeders\Translations\CorporateConventionsFrontendTranslations;

$locales = ['it', 'en', 'de', 'fr'];

it('has a non-empty translation for every key in the four locales', function () use ($locales): void {
    $definitions = CorporateConventionsFrontendTranslations::definitions();

    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $tuple) {
        expect($tuple)->toHaveCount(4, $key);

        foreach ($tuple as $text) {
            expect(trim((string) $text))->not->toBe('', $key);
        }
    }

    foreach ($locales as $locale) {
        expect(CorporateConventionsFrontendTranslations::all($locale))->toHaveCount(count($definitions));
    }
});

it('keeps the same placeholders in every locale of a key', function (): void {
    foreach (CorporateConventionsFrontendTranslations::definitions() as $key => $tuple) {
        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/\{(\w+)\}/', $text, $matches);
            sort($matches[1]);

            return $matches[1];
        }, $tuple);

        expect(array_unique(array_map('json_encode', $placeholders)))->toHaveCount(1, $key);
    }
});

it('namespaces every key and rejects an unsupported locale', function (): void {
    foreach (array_keys(CorporateConventionsFrontendTranslations::definitions()) as $key) {
        expect($key)->toMatch('/^(corporateConventions\.|NAV\.CORPORATE_CONVENTIONS|PAGE_TITLE\.CORPORATE_CONVENTIONS)/');
    }

    expect(fn () => CorporateConventionsFrontendTranslations::all('es'))->toThrow(InvalidArgumentException::class);
});

it('has the same backend dictionary keys in the four locales', function () use ($locales): void {
    $flatten = static function (array $items, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($items as $key => $value) {
            is_array($value) ? $keys = [...$keys, ...$flatten($value, $prefix.$key.'.')] : $keys[] = $prefix.$key;
        }

        return $keys;
    };

    $reference = $flatten(require base_path('lang/it/corporate_conventions.php'));
    sort($reference);

    foreach ($locales as $locale) {
        $keys = $flatten(require base_path('lang/'.$locale.'/corporate_conventions.php'));
        sort($keys);

        expect($keys)->toBe($reference, $locale);
    }
});
