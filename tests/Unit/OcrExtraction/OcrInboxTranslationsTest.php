<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\OcrInboxFrontendTranslations;

test('every OCR_INBOX key exists in the four locales with a non-empty text', function (): void {
    $keys = array_keys(OcrInboxFrontendTranslations::definitions());

    expect($keys)->not->toBeEmpty();

    foreach (['en', 'it', 'de', 'fr'] as $locale) {
        $dictionary = FrontendTranslationSeeder::dictionary($locale);

        foreach ($keys as $key) {
            expect(trim($dictionary[$key] ?? ''))->not->toBe('', "{$locale} is missing {$key}");
        }
    }

    foreach (OcrInboxFrontendTranslations::definitions() as $key => $values) {
        expect($values)->toHaveCount(4)->and(str_starts_with($key, 'OCR_INBOX.'))->toBeTrue();
    }
});

test('the four languages differ where the words differ', function (): void {
    expect(OcrInboxFrontendTranslations::all('it')['OCR_INBOX.CONFIRM'])->toBe('Conferma e registra')
        ->and(OcrInboxFrontendTranslations::all('en')['OCR_INBOX.CONFIRM'])->toBe('Confirm & post document')
        ->and(OcrInboxFrontendTranslations::all('de')['OCR_INBOX.CONFIRM'])->toBe('Bestätigen und buchen')
        ->and(OcrInboxFrontendTranslations::all('fr')['OCR_INBOX.CONFIRM'])->toBe('Confirmer et comptabiliser');
});

test('placeholders are the same in every language', function (): void {
    foreach (OcrInboxFrontendTranslations::definitions() as $key => $values) {
        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/\{(\w+)\}/', $text, $found);
            sort($found[1]);

            return $found[1];
        }, $values);

        expect(array_unique(array_map('json_encode', $placeholders)))->toHaveCount(1, "{$key} placeholders differ between languages");
    }
});

test('the Swiss spelling of German keeps the sharp s out', function (): void {
    foreach (OcrInboxFrontendTranslations::definitions() as $key => $values) {
        expect($values[2])->not->toContain('ß', "{$key} uses ß in German");
    }
});
