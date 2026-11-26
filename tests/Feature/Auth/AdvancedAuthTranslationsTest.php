<?php

declare(strict_types=1);

use App\Mail\AccountLockedMail;
use App\Mail\AccountUnlockedMail;
use App\Models\User;
use App\Services\Auth\Security\SecurityAuditCatalog;
use Database\Seeders\Translations\AdvancedAuthEnterpriseFrontendTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;

uses(RefreshDatabase::class);

const ADVANCED_AUTH_LOCALES = ['it', 'de', 'fr', 'en'];

/**
 * @param  array<string, mixed>  $messages
 * @return array<string, string>
 */
function advancedAuthFlatten(array $messages, string $prefix = ''): array
{
    $flat = [];

    foreach ($messages as $key => $value) {
        is_array($value) ? $flat += advancedAuthFlatten($value, $prefix.$key.'.') : $flat[$prefix.$key] = (string) $value;
    }

    return $flat;
}

function advancedAuthPlaceholders(string $text, string $style): array
{
    preg_match_all($style === 'lang' ? '/:([a-z_]+)/' : '/\{([a-z_]+)\}/', $text, $matches);
    sort($matches[1]);

    return array_values(array_unique($matches[1]));
}

test('the backend dictionary has the same keys and placeholders in every language', function (): void {
    $dictionaries = [];

    foreach (ADVANCED_AUTH_LOCALES as $locale) {
        $dictionaries[$locale] = advancedAuthFlatten(require lang_path($locale.'/advanced_auth.php'));
    }

    foreach (ADVANCED_AUTH_LOCALES as $locale) {
        expect(array_keys($dictionaries[$locale]))->toBe(array_keys($dictionaries['en']), $locale);

        foreach ($dictionaries[$locale] as $key => $text) {
            expect(trim($text))->not->toBe('', $locale.' '.$key);
            expect(advancedAuthPlaceholders($text, 'lang'))->toBe(advancedAuthPlaceholders($dictionaries['en'][$key], 'lang'), $locale.' '.$key);
        }
    }
});

test('the mails render translated in every language', function (): void {
    $user = new User(['name' => 'Marco Neri', 'first_name' => 'Marco', 'email' => 'marco.neri@example.com', 'locale' => 'it']);

    foreach (ADVANCED_AUTH_LOCALES as $locale) {
        App::setLocale($locale);

        $locked = (new AccountLockedMail($user, 15, now()->addMinutes(15), '203.0.113.7', 'Chrome on Windows'))->render();
        $unlocked = (new AccountUnlockedMail($user))->render();

        expect($locked)->toContain('203.0.113.7')->toContain('15')->not->toContain('advanced_auth.')
            ->and($unlocked)->not->toContain('advanced_auth.')
            ->and((new AccountLockedMail($user, 15, now(), null, null))->envelope()->subject)->not->toContain('advanced_auth.');
    }

    App::setLocale('en');
    expect((new AccountLockedMail($user, 15, now(), null, null))->render())->toContain(__('advanced_auth.mail.unknown'));
});

test('the API-served dictionary is complete in the four languages', function (): void {
    $definitions = AdvancedAuthEnterpriseFrontendTranslations::definitions();

    foreach ($definitions as $key => $values) {
        expect($values)->toHaveCount(4, $key);

        foreach ($values as $index => $text) {
            expect(trim($text))->not->toBe('', $key.' #'.$index);
            expect(advancedAuthPlaceholders($text, 'js'))->toBe(advancedAuthPlaceholders($values[1], 'js'), $key.' #'.$index);
        }

        expect($key)->toMatch('/^security\.(audit|scim\.groups|lockout|password)\./');
    }

    $dictionaries = [];

    foreach (ADVANCED_AUTH_LOCALES as $locale) {
        $dictionaries[$locale] = AdvancedAuthEnterpriseFrontendTranslations::all($locale);
        expect(array_keys($dictionaries[$locale]))->toBe(array_keys($definitions));
    }

    expect($dictionaries['it'])->not->toBe($dictionaries['en'])->and($dictionaries['de'])->not->toBe($dictionaries['fr']);
});

test('every audit event type, category, status, method and password rule has a label', function (): void {
    $keys = array_keys(AdvancedAuthEnterpriseFrontendTranslations::all('en'));
    $missing = [];

    foreach (SecurityAuditCatalog::eventTypes() as $type) {
        foreach ([$type['translation_key'], 'security.audit.category.'.$type['category'], 'security.audit.status.'.$type['status']] as $key) {
            in_array($key, $keys, true) || $missing[] = $key;
        }
    }

    foreach (['password', 'sso', 'sso_saml', 'sso_oidc', 'passkey'] as $method) {
        in_array('security.audit.auth_method.'.$method, $keys, true) || $missing[] = 'auth_method.'.$method;
    }

    foreach (['min_length', 'mixed_case', 'numbers', 'symbols', 'reused', 'breached'] as $rule) {
        in_array('security.password.rules.'.$rule, $keys, true) || $missing[] = 'rules.'.$rule;
    }

    foreach ([0, 1, 2, 3, 4] as $strength) {
        in_array('security.password.strength.'.$strength, $keys, true) || $missing[] = 'strength.'.$strength;
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});

test('the keys never overlap the other API-served dictionaries', function (): void {
    $mine = array_keys(AdvancedAuthEnterpriseFrontendTranslations::all('en'));

    foreach (glob(database_path('seeders/Translations/*FrontendTranslations.php')) ?: [] as $file) {
        $class = 'Database\\Seeders\\Translations\\'.basename($file, '.php');

        if ($class === AdvancedAuthEnterpriseFrontendTranslations::class || ! method_exists($class, 'all')) {
            continue;
        }

        expect(array_intersect($mine, array_keys($class::all('en'))))->toBe([], $class);
    }
});
