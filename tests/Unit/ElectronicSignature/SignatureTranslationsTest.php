<?php

declare(strict_types=1);

use Database\Seeders\Translations\ElectronicSignatureFrontendTranslations;
use Database\Seeders\Translations\ElectronicSignatureNotificationTranslations;
use Modules\ElectronicSignature\Enums\AuditEvent;
use Modules\ElectronicSignature\Enums\AuthMethod;
use Modules\ElectronicSignature\Enums\EnvelopeStatus;
use Modules\ElectronicSignature\Enums\Jurisdiction;
use Modules\ElectronicSignature\Enums\ProviderJurisdiction;
use Modules\ElectronicSignature\Enums\ProviderType;
use Modules\ElectronicSignature\Enums\RecipientRole;
use Modules\ElectronicSignature\Enums\RecipientStatus;
use Modules\ElectronicSignature\Enums\RoutingMode;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Enums\SignatureLevel;

/**
 * Every text of the module lives in the seeders (it, en, de, fr), never in the code: these tests fail when a new code,
 * enum case or placeholder is added without its four translations.
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
 */
function esigDictionary(): array
{
    return ElectronicSignatureFrontendTranslations::definitions() + ElectronicSignatureNotificationTranslations::definitions();
}

it('has no key defined in both seeders and no empty translation', function (): void {
    $overlap = array_intersect_key(ElectronicSignatureFrontendTranslations::definitions(), ElectronicSignatureNotificationTranslations::definitions());

    expect($overlap)->toBe([]);

    foreach (esigDictionary() as $key => $tuple) {
        foreach ($tuple as $index => $text) {
            expect(trim($text))->not->toBe('', "{$key} has an empty text at index {$index}");
        }
    }
});

it('keeps the same placeholders in the four languages of a text', function (): void {
    foreach (esigDictionary() as $key => $tuple) {
        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/(?:\{[a-zA-Z]+\}|:[a-z]+)/', $text, $matches);
            $found = array_unique($matches[0]);
            sort($found);

            return $found;
        }, $tuple);

        expect($placeholders[1])->toBe($placeholders[0], "{$key}: Italian and English differ")
            ->and($placeholders[2])->toBe($placeholders[0], "{$key}: German differs")
            ->and($placeholders[3])->toBe($placeholders[0], "{$key}: French differs");
    }
});

it('serves every language and refuses an unknown one', function (): void {
    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(ElectronicSignatureFrontendTranslations::all($locale))->not->toBeEmpty()
            ->and(ElectronicSignatureNotificationTranslations::all($locale))->not->toBeEmpty();
    }

    expect(fn () => ElectronicSignatureFrontendTranslations::all('es'))->toThrow(InvalidArgumentException::class);
});

it('translates every error code the module can raise', function (): void {
    $dictionary = esigDictionary();
    $root = dirname(__DIR__, 3).'/modules/78_ElectronicSignature/src';
    $codes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && preg_match_all("/new SignatureException\('([a-z_]+)'/", (string) file_get_contents($file->getPathname()), $matches) > 0) {
            array_push($codes, ...$matches[1]);
        }
    }

    expect($codes)->not->toBeEmpty();

    foreach (array_unique($codes) as $code) {
        expect($dictionary)->toHaveKey("signatures.error.{$code}");
    }
});

it('translates every case of the enums the screens and documents show', function (): void {
    $dictionary = esigDictionary();
    $prefixes = [
        'signatures.status.' => EnvelopeStatus::class,
        'signatures.recipientStatus.' => RecipientStatus::class,
        'signatures.role.' => RecipientRole::class,
        'signatures.authMethod.' => AuthMethod::class,
        'signatures.routing.' => RoutingMode::class,
        'signatures.signableType.' => SignableType::class,
        'signatures.provider.' => ProviderType::class,
        'signatures.providerJurisdiction.' => ProviderJurisdiction::class,
        'signatures.jurisdictionBadge.' => Jurisdiction::class,
        'signatures.jurisdiction.' => Jurisdiction::class,
        'signatures.level.' => SignatureLevel::class,
        'signatures.levelLong.' => SignatureLevel::class,
        'signatures.levelHint.' => SignatureLevel::class,
        'signatures.audit.' => AuditEvent::class,
    ];

    foreach ($prefixes as $prefix => $enum) {
        foreach ($enum::cases() as $case) {
            expect($dictionary)->toHaveKey($prefix.$case->value);
        }
    }

    foreach (SignatureLevel::cases() as $level) {
        foreach (Jurisdiction::cases() as $jurisdiction) {
            expect($dictionary)->toHaveKey("signatures.consent.legal.{$level->value}.{$jurisdiction->value}");
        }
    }
});
