<?php

declare(strict_types=1);

use Database\Seeders\Translations\SwissVatFrontendTranslations;

test('Swiss VAT backend lang files have exact key parity across it, de, fr, en', function (): void {
    $root = dirname(__DIR__, 3);
    $locales = ['it', 'de', 'fr', 'en'];
    $dictionaries = [];

    foreach ($locales as $locale) {
        $path = $root.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.'swiss_vat.php';
        expect(file_exists($path))->toBeTrue();
        /** @var array<string, array<string, string>> $loaded */
        $loaded = require $path;
        $dictionaries[$locale] = $loaded;
    }

    $baseSections = array_keys($dictionaries['it']);
    sort($baseSections);

    foreach ($locales as $locale) {
        $sections = array_keys($dictionaries[$locale]);
        sort($sections);
        expect($sections)->toBe($baseSections);

        foreach ($baseSections as $section) {
            $baseKeys = array_keys($dictionaries['it'][$section]);
            sort($baseKeys);
            $keys = array_keys($dictionaries[$locale][$section]);
            sort($keys);
            expect($keys)->toBe($baseKeys);

            foreach ($keys as $key) {
                expect(trim((string) $dictionaries[$locale][$section][$key]))->not->toBe('');
            }
        }
    }
});

test('SwissVatFrontendTranslations defines non-empty entries across it, en, de, fr', function (): void {
    $defs = SwissVatFrontendTranslations::definitions();
    expect($defs)->not->toBeEmpty();

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $all = SwissVatFrontendTranslations::all($locale);
        expect(array_keys($all))->toBe(array_keys($defs));

        foreach ($all as $key => $value) {
            expect(trim($value))->not->toBe('');
        }
    }
});
