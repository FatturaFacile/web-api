<?php

declare(strict_types=1);

use App\Services\Auth\RegistrationAudit;
use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\AdvancedAuthMfaFrontendTranslations;
use Database\Seeders\TranslationSeeder;

/**
 * @param  array<string, mixed>  $array
 * @return list<string>
 */
function mfaFlattenKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            array_push($keys, ...mfaFlattenKeys($value, $path));
        } else {
            $keys[] = $path;
        }
    }

    sort($keys);

    return $keys;
}

it('keeps the backend two-factor dictionary identical in the four locales', function (): void {
    $english = mfaFlattenKeys(require base_path('lang/en/two_factor.php'));

    foreach (['it', 'de', 'fr'] as $locale) {
        $translated = require base_path("lang/{$locale}/two_factor.php");

        expect(mfaFlattenKeys($translated))->toBe($english, $locale);

        array_walk_recursive($translated, static function (mixed $text) use ($locale): void {
            expect(trim((string) $text))->not->toBe('', $locale);
        });
    }

    expect($english)->toContain('mail.code.subject', 'messages.email_code_sent', 'errors.email_throttled_detail');
});

it('keeps the placeholders of the backend two-factor texts identical in the four locales', function (): void {
    $placeholders = static function (array $dictionary): array {
        $found = [];

        foreach (mfaFlattenKeys($dictionary) as $path) {
            preg_match_all('/:\w+/', (string) data_get($dictionary, $path), $matches);
            sort($matches[0]);
            $found[$path] = $matches[0];
        }

        return $found;
    };

    $english = require base_path('lang/en/two_factor.php');

    foreach (['it', 'de', 'fr'] as $locale) {
        expect($placeholders(require base_path("lang/{$locale}/two_factor.php")))->toBe($placeholders($english), $locale);
    }
});

it('translates every frontend MFA key into the four languages', function (): void {
    foreach (AdvancedAuthMfaFrontendTranslations::definitions() as $key => $texts) {
        expect($texts)->toHaveCount(4, $key);

        foreach ($texts as $text) {
            expect(trim($text))->not->toBe('', $key);
        }
    }
});

it('keeps the placeholders of the frontend MFA texts identical in the four languages', function (): void {
    foreach (AdvancedAuthMfaFrontendTranslations::definitions() as $key => $texts) {
        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/\{\w+}/', $text, $matches);
            sort($matches[0]);

            return $matches[0];
        }, $texts);

        expect(array_unique(array_map('json_encode', $placeholders)))->toHaveCount(1, $key);
    }
});

it('uses only the auth.mfa and security.mfa prefixes and covers the factors, the challenge and the trusted devices', function (): void {
    $keys = array_keys(AdvancedAuthMfaFrontendTranslations::definitions());

    foreach ($keys as $key) {
        expect(str_starts_with($key, 'auth.mfa.') || str_starts_with($key, 'security.mfa.'))->toBeTrue($key);
    }

    $required = [
        ...array_map(static fn (string $method): string => "auth.mfa.method.{$method}", ['totp', 'email', 'webauthn', 'recovery_code']),
        'auth.mfa.challenge.switch_method',
        'auth.mfa.challenge.remember_device',
        'auth.mfa.challenge.use_recovery_code',
        'auth.mfa.challenge.send_code',
        'auth.mfa.challenge.resend',
        'auth.mfa.challenge.webauthn_prompt',
        'security.mfa.email.enable',
        'security.mfa.email.confirm',
        'security.mfa.trusted.revoke',
        'security.mfa.trusted.revoke_all',
        'security.mfa.trusted.empty',
        'security.mfa.trusted.column.device',
    ];

    expect(array_diff($required, $keys))->toBe([]);
});

it('registers the frontend MFA dictionary for every seeded locale without key overlap', function (): void {
    foreach (TranslationSeeder::LOCALES as $locale) {
        $dictionary = FrontendTranslationSeeder::dictionary($locale);

        expect($dictionary)->toHaveKey('auth.mfa.method.email')
            ->and($dictionary)->toHaveKey('security.mfa.trusted.title');

        // Another dictionary that already owned one of the keys would win the merge: the texts must be ours.
        foreach (AdvancedAuthMfaFrontendTranslations::all($locale) as $key => $text) {
            expect($dictionary[$key])->toBe($text, "{$locale} {$key}");
        }
    }
});

it('declares every new two-factor audit action with a unique code', function (): void {
    $constants = array_filter(
        (new ReflectionClass(RegistrationAudit::class))->getConstants(),
        static fn (string $name): bool => str_starts_with($name, 'TRUSTED_DEVICE') || str_starts_with($name, 'TWO_FACTOR_'),
        ARRAY_FILTER_USE_KEY,
    );

    expect($constants)->toHaveKeys([
        'TWO_FACTOR_CHALLENGE_PASSED', 'TWO_FACTOR_CHALLENGE_FAILED', 'TWO_FACTOR_EMAIL_ENABLED', 'TWO_FACTOR_EMAIL_DISABLED',
        'TWO_FACTOR_EMAIL_CODE_SENT', 'TWO_FACTOR_WEBAUTHN_USED', 'TRUSTED_DEVICE_CREATED', 'TRUSTED_DEVICE_USED',
        'TRUSTED_DEVICE_REVOKED', 'TRUSTED_DEVICES_REVOKED',
    ])
        ->and(array_unique(array_values((new ReflectionClass(RegistrationAudit::class))->getConstants())))
        ->toHaveCount(count((new ReflectionClass(RegistrationAudit::class))->getConstants()));
});
