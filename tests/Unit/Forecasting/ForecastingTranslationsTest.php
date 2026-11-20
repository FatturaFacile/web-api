<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\AiForecastingFrontendTranslations;
use Modules\Forecasting\Domain\Enums\JurisdictionCode;
use Modules\Forecasting\Domain\Enums\PaymentRiskTier;
use Modules\Forecasting\Domain\Services\StatutoryCashOutflowCalendarService;
use Modules\Forecasting\Domain\ValueObjects\StatutoryProfile;

test('every AI_FORECAST key exists in the four locales with a non-empty text', function (): void {
    $keys = array_keys(AiForecastingFrontendTranslations::definitions());

    foreach (['en', 'it', 'de', 'fr'] as $locale) {
        $dictionary = FrontendTranslationSeeder::dictionary($locale);

        foreach ($keys as $key) {
            expect(trim($dictionary[$key] ?? ''))->not->toBe('', "{$locale} is missing {$key}");
        }
    }

    foreach (AiForecastingFrontendTranslations::definitions() as $key => $values) {
        expect($values)->toHaveCount(4)->and(str_starts_with($key, 'AI_FORECAST.'))->toBeTrue();
    }
});

test('the four languages differ from each other where the words differ', function (): void {
    expect(AiForecastingFrontendTranslations::all('it')['AI_FORECAST.TIER_CRITICAL'])->toBe('Critico')
        ->and(AiForecastingFrontendTranslations::all('en')['AI_FORECAST.TIER_CRITICAL'])->toBe('Critical')
        ->and(AiForecastingFrontendTranslations::all('de')['AI_FORECAST.TIER_CRITICAL'])->toBe('Kritisch')
        ->and(AiForecastingFrontendTranslations::all('fr')['AI_FORECAST.TIER_CRITICAL'])->toBe('Critique');
});

test('placeholders are the same in every language', function (): void {
    foreach (AiForecastingFrontendTranslations::definitions() as $key => $values) {
        $placeholders = array_map(static fn (string $text): array => (preg_match_all('/\{(\w+)\}/', $text, $found) ? $found[1] : []), $values);

        expect(array_unique(array_map('json_encode', $placeholders)))->toHaveCount(1, "{$key} placeholders differ between languages");
    }
});

test('the labels the screens build from risk tiers and statutory event codes are translated', function (): void {
    $keys = array_map(static fn (PaymentRiskTier $tier): string => 'AI_FORECAST.TIER_'.strtoupper($tier->value), PaymentRiskTier::cases());

    // Every event code the calendar can produce, for a profile that fires all of them.
    $profile = new StatutoryProfile(1000.0, 1000.0, 1000.0, 1000.0, 1000.0, 1000.0, 1000.0, true, true);
    $calendar = new StatutoryCashOutflowCalendarService;

    foreach (JurisdictionCode::cases() as $jurisdiction) {
        foreach ($calendar->eventsBetween($jurisdiction, new DateTimeImmutable('2028-01-01'), new DateTimeImmutable('2029-12-31'), $profile) as $event) {
            $keys[] = 'AI_FORECAST.EVENT_'.$event->code;
        }
    }

    $defined = AiForecastingFrontendTranslations::definitions();

    foreach (array_unique($keys) as $key) {
        expect($defined)->toHaveKey($key);
    }
});
