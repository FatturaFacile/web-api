<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Modules\Platform\Localization\Domain\Events\LocaleSettingsChanged;
use Modules\Platform\Localization\Infrastructure\Policies\TranslationPolicy;

require_once __DIR__.'/LocalizationSupport.php';

beforeEach(function (): void {
    locSetUp($this);
});

it('stores the interface language of the signed-in user', function (): void {
    $this->putJson('/api/v1/profile/locale', ['locale' => 'fr-CH'])->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.locale', 'fr-CH');

    expect(User::query()->find($this->owner->id)?->locale)->toBe('fr-CH');
});

it('rejects an unsupported profile locale', function (): void {
    $this->putJson('/api/v1/profile/locale', ['locale' => 'es-ES'])->assertStatus(422)->assertJsonValidationErrors('locale');
    $this->putJson('/api/v1/profile/locale', [])->assertStatus(422)->assertJsonValidationErrors('locale');

    expect(User::query()->find($this->owner->id)?->locale)->toBe('en');
});

it('requires authentication for the profile locale', function (): void {
    $this->app['auth']->forgetGuards();

    $this->putJson('/api/v1/profile/locale', ['locale' => 'fr-CH'])->assertUnauthorized();
});

it('derives the default localization settings from the country of the company', function (): void {
    $swiss = $this->getJson('/api/v1/settings/localization')->assertOk();

    expect($swiss->json('data.default_locale'))->toBe('it-CH')
        ->and($swiss->json('data.active_locales'))->toBe(['it-CH', 'de-CH', 'fr-CH', 'en'])
        ->and($swiss->json('data.number_format'))->toBeNull()
        ->and($swiss->json('data.date_format'))->toBeNull()
        ->and(collect($swiss->json('data.available_locales'))->pluck('code')->all())->toBe(['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en'])
        ->and(collect($swiss->json('data.available_locales'))->firstWhere('code', 'de-CH'))->toMatchArray(['language' => 'de', 'region' => 'CH', 'currency' => 'CHF', 'ng_zorro_locale' => 'de_DE']);

    locActor($this, 'owner', $this->otherTenant);
    $italian = $this->getJson('/api/v1/settings/localization')->assertOk();

    expect($italian->json('data.default_locale'))->toBe('it-IT')->and($italian->json('data.active_locales'))->toBe(['it-IT', 'en']);
});

it('saves the localization settings of the company and announces the change', function (): void {
    Event::fake([LocaleSettingsChanged::class]);

    $payload = [
        'active_locales' => ['de-CH', 'fr-CH', 'en'],
        'default_locale' => 'de-CH',
        'number_format' => ['decimal_separator' => ',', 'thousands_separator' => '.'],
        'date_format' => 'dd/MM/yyyy',
    ];

    $this->putJson('/api/v1/settings/localization', $payload)->assertOk()
        ->assertJsonPath('data.default_locale', 'de-CH')
        ->assertJsonPath('data.number_format.decimal_separator', ',');

    $this->getJson('/api/v1/settings/localization')->assertOk()
        ->assertJsonPath('data.active_locales', ['de-CH', 'fr-CH', 'en'])
        ->assertJsonPath('data.date_format', 'dd/MM/yyyy');

    Event::assertDispatched(LocaleSettingsChanged::class, fn (LocaleSettingsChanged $event): bool => $event->tenantId === $this->tenant->id);

    // The settings of one company never leak into another
    locActor($this, 'owner', $this->otherTenant);
    $this->getJson('/api/v1/settings/localization')->assertOk()->assertJsonPath('data.default_locale', 'it-IT');
});

it('lets the number format fall back to the locale by sending null', function (): void {
    $this->putJson('/api/v1/settings/localization', ['active_locales' => ['it-CH'], 'default_locale' => 'it-CH', 'number_format' => null, 'date_format' => null])->assertOk()
        ->assertJsonPath('data.number_format', null);
});

it('validates the localization settings', function (array $payload, string $field): void {
    $this->putJson('/api/v1/settings/localization', $payload)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no active locale' => [['active_locales' => [], 'default_locale' => 'en'], 'active_locales'],
    'unsupported locale' => [['active_locales' => ['en', 'es-ES'], 'default_locale' => 'en'], 'active_locales.1'],
    'duplicate locale' => [['active_locales' => ['en', 'en'], 'default_locale' => 'en'], 'active_locales.0'],
    'default not active' => [['active_locales' => ['en'], 'default_locale' => 'de-CH'], 'default_locale'],
    'same separators' => [['active_locales' => ['en'], 'default_locale' => 'en', 'number_format' => ['decimal_separator' => '.', 'thousands_separator' => '.']], 'number_format.thousands_separator'],
    'bad decimal separator' => [['active_locales' => ['en'], 'default_locale' => 'en', 'number_format' => ['decimal_separator' => ';', 'thousands_separator' => ',']], 'number_format.decimal_separator'],
    'bad date pattern' => [['active_locales' => ['en'], 'default_locale' => 'en', 'date_format' => 'MM/dd/yy'], 'date_format'],
]);

it('returns the settings validation message in the language of the user', function (): void {
    locActor($this, 'owner', null, 'de-CH');

    $message = $this->putJson('/api/v1/settings/localization', ['active_locales' => ['en'], 'default_locale' => 'de-CH'])->assertStatus(422)->json('errors.default_locale.0');

    expect($message)->toBe('Die Standardsprache muss eine der aktiven Sprachen sein.');

    locActor($this, 'owner', null, 'fr-CH');
    expect($this->putJson('/api/v1/settings/localization', ['active_locales' => ['en'], 'default_locale' => 'de-CH'])->json('errors.default_locale.0'))
        ->toBe('La langue par défaut doit être l’une des langues actives.');
});

it('falls back on the Accept-Language header, then on the company default, to pick the message language', function (): void {
    $user = locActor($this, 'owner');
    $user->forceFill(['locale' => 'xx'])->save();

    $german = $this->putJson('/api/v1/settings/localization', ['active_locales' => ['en'], 'default_locale' => 'de-CH'], ['Accept-Language' => 'de-CH,de;q=0.9'])->json('errors.default_locale.0');

    expect($german)->toBe('Die Standardsprache muss eine der aktiven Sprachen sein.');
});

it('forbids a member who is not owner or administrator to change the settings, but lets them read', function (): void {
    locActor($this, 'accountant');

    $this->getJson('/api/v1/settings/localization')->assertOk();
    $this->putJson('/api/v1/settings/localization', ['active_locales' => ['en'], 'default_locale' => 'en'])->assertForbidden();
});

it('applies the translation policy: members read, owners and administrators write, locked keys are immutable', function (): void {
    $policy = app(TranslationPolicy::class);
    $admin = locActor($this, 'admin');
    $accountant = locActor($this, 'accountant');
    $outsider = locActor($this, 'owner', $this->otherTenant);

    expect($policy->viewAny($accountant, $this->tenant->id))->toBeTrue()
        ->and($policy->viewAny($outsider, $this->tenant->id))->toBeFalse()
        ->and($policy->update($this->owner, $this->tenant->id))->toBeTrue()
        ->and($policy->update($admin, $this->tenant->id))->toBeTrue()
        ->and($policy->update($accountant, $this->tenant->id))->toBeFalse()
        ->and($policy->update($outsider, $this->tenant->id))->toBeFalse()
        ->and($policy->update($this->owner, $this->tenant->id, locked: true))->toBeFalse()
        ->and($policy->manageSettings($admin, $this->tenant->id))->toBeTrue();
});
