<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\AiCognitiveFrontendTranslations;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\Enums\SuggestionStatus;

test('every AI_COG key exists in the four locales with a non-empty text', function (): void {
    $keys = array_map(static fn (string $key): string => 'AI_COG.'.$key, array_keys(AiCognitiveFrontendTranslations::definitions()));

    expect($keys)->not->toBeEmpty();

    foreach (['en', 'it', 'de', 'fr'] as $locale) {
        $dictionary = FrontendTranslationSeeder::dictionary($locale);

        foreach ($keys as $key) {
            expect(trim($dictionary[$key] ?? ''))->not->toBe('', "{$locale} is missing {$key}");
        }
    }

    foreach (AiCognitiveFrontendTranslations::definitions() as $key => $values) {
        expect($values)->toHaveCount(4);
    }
});

test('the four languages differ where the words differ', function (): void {
    expect(AiCognitiveFrontendTranslations::all('it')['AI_COG.EDIT_AND_APPROVE'])->toBe('Modifica e approva')
        ->and(AiCognitiveFrontendTranslations::all('en')['AI_COG.EDIT_AND_APPROVE'])->toBe('Edit and approve')
        ->and(AiCognitiveFrontendTranslations::all('de')['AI_COG.EDIT_AND_APPROVE'])->toBe('Bearbeiten und genehmigen')
        ->and(AiCognitiveFrontendTranslations::all('fr')['AI_COG.EDIT_AND_APPROVE'])->toBe('Modifier et approuver');
});

test('placeholders are the same in every language', function (): void {
    foreach (AiCognitiveFrontendTranslations::definitions() as $key => $values) {
        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/\{(\w+)\}/', $text, $found);
            sort($found[1]);

            return $found[1];
        }, $values);

        expect(array_unique(array_map('json_encode', $placeholders)))->toHaveCount(1, "{$key} placeholders differ between languages");
    }
});

test('the Swiss spelling of German keeps the sharp s out', function (): void {
    foreach (AiCognitiveFrontendTranslations::definitions() as $key => $values) {
        expect($values[2])->not->toContain('ß', "{$key} uses ß in German");
    }
});

test('every workflow, kind and status the API can send has a label', function (): void {
    $keys = array_keys(AiCognitiveFrontendTranslations::definitions());

    foreach (SuggestionKind::cases() as $kind) {
        expect($keys)->toContain('KIND_'.strtoupper($kind->value))->toContain('WORKFLOW_'.strtoupper($kind->workflow()));
    }

    foreach (SuggestionStatus::cases() as $status) {
        expect($keys)->toContain('STATUS_'.strtoupper($status->value));
    }

    foreach (AiProviderType::cases() as $provider) {
        expect($keys)->toContain('PROVIDER_'.strtoupper($provider->value));
    }
});
