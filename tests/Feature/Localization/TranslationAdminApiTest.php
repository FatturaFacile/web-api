<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Modules\Platform\Localization\Domain\Events\TranslationsChanged;

require_once __DIR__.'/LocalizationSupport.php';

beforeEach(function (): void {
    locSetUp($this);
    locSeedDictionary();
});

function translationRows(object $response): array
{
    return collect($response->json('data'))->keyBy('key')->all();
}

it('lists the keys of a locale with the effective value, the origin and the flags', function (): void {
    $response = $this->getJson('/api/v1/settings/translations?filter[locale]=de-CH')->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.total', 4);

    $rows = translationRows($response);

    expect($rows['invoicing.title']['value'])->toBe('Rechnung')
        ->and($rows['invoicing.title']['is_missing'])->toBeFalse()
        ->and($rows['invoicing.title']['is_overridden'])->toBeFalse()
        ->and($rows['invoicing.title']['group'])->toBe('invoicing')
        ->and($rows['invoicing.subtitle']['is_missing'])->toBeTrue()
        ->and($rows['invoicing.subtitle']['value'])->toBe('Sales invoices')
        ->and($rows['statutory.ch.vat_suffix']['is_locked'])->toBeTrue()
        ->and($response->json('meta.groups'))->toBe(['crm', 'invoicing', 'statutory'])
        ->and($response->json('meta.missing_count'))->toBe(1);
});

it('filters by group, missing keys and search text and paginates', function (): void {
    $group = $this->getJson('/api/v1/settings/translations?filter[locale]=de-CH&filter[group]=invoicing')->assertOk();
    expect(array_keys(translationRows($group)))->toBe(['invoicing.subtitle', 'invoicing.title']);

    $missing = $this->getJson('/api/v1/settings/translations?filter[locale]=de-CH&filter[missing]=1')->assertOk();
    expect(array_keys(translationRows($missing)))->toBe(['invoicing.subtitle'])->and($missing->json('meta.total'))->toBe(1);

    $search = $this->getJson('/api/v1/settings/translations?filter[locale]=de-CH&filter[search]=kontakt')->assertOk();
    expect(array_keys(translationRows($search)))->toBe(['crm.title']);

    $page = $this->getJson('/api/v1/settings/translations?filter[locale]=de-CH&per_page=2&page=2')->assertOk();
    expect($page->json('data'))->toHaveCount(2)->and($page->json('meta.last_page'))->toBe(2)->and($page->json('meta.current_page'))->toBe(2);
});

it('treats an underscore in the group filter literally', function (): void {
    locSystemText('en', 'my_group.key', 'x');
    locSystemText('en', 'myXgroup.key', 'y');

    $rows = translationRows($this->getJson('/api/v1/settings/translations?filter[locale]=en&filter[group]=my_group')->assertOk());

    expect(array_keys($rows))->toBe(['my_group.key']);
});

it('shows a tenant override as such and keeps it out of another tenant', function (): void {
    locText($this->tenant->id, 'de-CH', 'invoicing.title', 'Faktura');

    $own = translationRows($this->getJson('/api/v1/settings/translations?filter[locale]=de-CH')->assertOk());
    locActor($this, 'owner', $this->otherTenant);
    $other = translationRows($this->getJson('/api/v1/settings/translations?filter[locale]=de-CH')->assertOk());

    expect($own['invoicing.title'])->toMatchArray(['value' => 'Faktura', 'is_overridden' => true, 'system_value' => 'Rechnung'])
        ->and($other['invoicing.title'])->toMatchArray(['value' => 'Rechnung', 'is_overridden' => false]);
});

it('lets owners and administrators save a tenant override', function (string $role): void {
    locActor($this, $role);

    $this->putJson('/api/v1/settings/translations/invoicing/title', ['locale' => 'de-CH', 'value' => 'Faktura'])->assertOk()
        ->assertJsonPath('data.key', 'invoicing.title')
        ->assertJsonPath('data.value', 'Faktura')
        ->assertJsonPath('data.is_overridden', true);

    expect(locStored($this->tenant->id, 'de-CH', 'invoicing.title'))->toBe('Faktura')
        ->and(locStored(null, 'de', 'invoicing.title'))->toBe('Rechnung');
})->with(['owner', 'admin']);

it('supports keys with several segments', function (): void {
    locSystemText('en', 'invoicing.list.empty', 'Nothing here');

    $this->putJson('/api/v1/settings/translations/invoicing/list.empty', ['locale' => 'en', 'value' => 'No invoices yet'])->assertOk()
        ->assertJsonPath('data.key', 'invoicing.list.empty');

    expect(locStored($this->tenant->id, 'en', 'invoicing.list.empty'))->toBe('No invoices yet');
});

it('dispatches TranslationsChanged when a text changes, and not when it is saved again unchanged', function (): void {
    Event::fake([TranslationsChanged::class]);

    $this->putJson('/api/v1/settings/translations/invoicing/title', ['locale' => 'de-CH', 'value' => 'Faktura'])->assertOk();
    $this->putJson('/api/v1/settings/translations/invoicing/title', ['locale' => 'de-CH', 'value' => 'Faktura'])->assertOk();

    Event::assertDispatchedTimes(TranslationsChanged::class, 1);
    Event::assertDispatched(TranslationsChanged::class, fn (TranslationsChanged $event): bool => $event->tenantId === $this->tenant->id && $event->locale === 'de-CH');
});

it('forbids a member who is not owner or administrator to write, but lets them read', function (): void {
    locActor($this, 'accountant');

    $this->getJson('/api/v1/settings/translations?filter[locale]=de-CH')->assertOk();
    $this->putJson('/api/v1/settings/translations/invoicing/title', ['locale' => 'de-CH', 'value' => 'Faktura'])->assertForbidden();

    expect(locStored($this->tenant->id, 'de-CH', 'invoicing.title'))->toBeNull();
});

it('refuses a member of another tenant', function (): void {
    locActor($this, 'owner', $this->otherTenant);
    // The header names a tenant the user does not belong to
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/translations?filter[locale]=de-CH')->assertStatus(403);
});

it('answers 403 Forbidden for a locked system key and writes nothing', function (): void {
    $this->putJson('/api/v1/settings/translations/statutory/ch.vat_suffix', ['locale' => 'de-CH', 'value' => 'Steuer'])->assertForbidden()
        ->assertJsonPath('status', 403)
        ->assertJsonPath('key', 'statutory.ch.vat_suffix');

    expect(locStored($this->tenant->id, 'de-CH', 'statutory.ch.vat_suffix'))->toBeNull()
        ->and(locStored(null, 'de', 'statutory.ch.vat_suffix'))->toBe('MWST');
});

it('validates the payload of an override', function (array $payload): void {
    $this->putJson('/api/v1/settings/translations/invoicing/title', $payload)->assertStatus(422);
})->with([
    'no locale' => [['value' => 'x']],
    'unsupported locale' => [['locale' => 'es-ES', 'value' => 'x']],
    'no value' => [['locale' => 'en']],
    'value too long' => [['locale' => 'en', 'value' => str_repeat('x', 5001)]],
]);

it('rejects a key that is not group.segment', function (): void {
    $this->putJson('/api/v1/settings/translations/invoicing/ti%20tle', ['locale' => 'en', 'value' => 'x'])->assertStatus(404);
    $this->putJson('/api/v1/settings/translations/invoicing/title', ['locale' => 'en', 'value' => 'x'])->assertOk();
});

it('returns the validation messages in the resolved locale of the user', function (): void {
    locActor($this, 'owner', null, 'de-CH');

    $message = $this->putJson('/api/v1/settings/translations/invoicing/title', ['locale' => 'es-ES', 'value' => 'x'])
        ->assertStatus(422)->json('errors.locale.0');

    expect($message)->toContain('Sprache');

    locActor($this, 'owner', null, 'it-CH');
    $italian = $this->putJson('/api/v1/settings/translations/invoicing/title', ['locale' => 'es-ES', 'value' => 'x'])->json('errors.locale.0');

    expect($italian)->not->toBe($message);
});

it('requires authentication', function (): void {
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/settings/translations?filter[locale]=de-CH')->assertUnauthorized();
    $this->putJson('/api/v1/settings/translations/invoicing/title', ['locale' => 'en', 'value' => 'x'])->assertUnauthorized();
});

it('requires the locale filter', function (): void {
    $this->getJson('/api/v1/settings/translations')->assertStatus(422)->assertJsonValidationErrors('filter.locale');
});
