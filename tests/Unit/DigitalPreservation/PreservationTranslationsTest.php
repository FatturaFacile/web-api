<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\PreservationFrontendTranslations;
use Modules\DigitalPreservation\Domain\Enums\AuditAction;
use Modules\DigitalPreservation\Domain\Enums\AutoSealFrequency;
use Modules\DigitalPreservation\Domain\Enums\BatchStatus;
use Modules\DigitalPreservation\Domain\Enums\DocumentCategory;
use Modules\DigitalPreservation\Domain\Enums\JurisdictionMode;
use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;

test('every PRESERVATION key exists in the four locales with a non-empty text', function (): void {
    $keys = array_keys(PreservationFrontendTranslations::definitions());

    foreach (['en', 'it', 'de', 'fr'] as $locale) {
        $dictionary = FrontendTranslationSeeder::dictionary($locale);

        foreach ($keys as $key) {
            expect(trim($dictionary[$key] ?? ''))->not->toBe('', "{$locale} is missing {$key}");
        }
    }

    foreach (PreservationFrontendTranslations::definitions() as $key => $values) {
        expect($values)->toHaveCount(4);
        expect(str_starts_with($key, 'PRESERVATION.'))->toBeTrue();
    }
});

test('the labels built at runtime from the enums are translated', function (): void {
    $keys = array_merge(
        array_map(fn (BatchStatus $c): string => 'PRESERVATION.STATUS_'.strtoupper($c->value), BatchStatus::cases()),
        array_map(fn (DocumentCategory $c): string => 'PRESERVATION.CATEGORY_'.strtoupper($c->value), DocumentCategory::cases()),
        array_map(fn (PreservationProvider $c): string => 'PRESERVATION.PROVIDER_'.strtoupper($c->value), PreservationProvider::cases()),
        array_map(fn (JurisdictionMode $c): string => 'PRESERVATION.JURISDICTION_'.$c->value, JurisdictionMode::cases()),
        array_map(fn (JurisdictionMode $c): string => 'PRESERVATION.JURISDICTION_TAG_'.$c->value, JurisdictionMode::cases()),
        array_map(fn (AutoSealFrequency $c): string => 'PRESERVATION.FREQUENCY_'.strtoupper($c->value), AutoSealFrequency::cases()),
        array_map(fn (AuditAction $c): string => 'PRESERVATION.ACTION_'.strtoupper($c->value), AuditAction::cases()),
    );

    foreach (['en', 'it', 'de', 'fr'] as $locale) {
        expect(array_diff($keys, array_keys(FrontendTranslationSeeder::dictionary($locale))))->toBe([], "{$locale} lacks enum labels");
    }
});
