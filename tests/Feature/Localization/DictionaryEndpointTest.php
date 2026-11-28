<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Modules\Platform\Localization\Domain\Events\TranslationsChanged;
use Modules\Platform\Localization\Domain\Services\TranslationFallbackResolver;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

require_once __DIR__.'/LocalizationSupport.php';

beforeEach(function (): void {
    locSetUp($this);
    locSeedDictionary();
});

const DICTIONARY_URL = '/api/v1/i18n/dictionary';

it('serves the dictionary with the version hash as ETag, in the envelope of the API', function (): void {
    $response = $this->getJson(DICTIONARY_URL.'?locale=de-CH')->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.locale', 'de-CH')
        ->assertHeader('ETag');

    $hash = $response->json('data.version_hash');
    $entries = $response->json('data.entries');

    expect($hash)->toBeString()->not->toBe('')
        ->and($response->headers->get('ETag'))->toBe('"'.$hash.'"')
        ->and($entries['invoicing.title'])->toBe('Rechnung')
        ->and($entries['invoicing.subtitle'])->toBe('Sales invoices')
        ->and($entries['statutory.ch.vat_suffix'])->toBe('MWST');
});

it('answers 304 Not Modified when the client holds the current version', function (): void {
    $hash = $this->getJson(DICTIONARY_URL.'?locale=de-CH')->json('data.version_hash');

    $this->getJson(DICTIONARY_URL.'?locale=de-CH', ['If-None-Match' => '"'.$hash.'"'])
        ->assertStatus(304)
        ->assertHeader('ETag', '"'.$hash.'"');

    $this->getJson(DICTIONARY_URL.'?locale=de-CH', ['If-None-Match' => '"stale"'])->assertOk();
});

it('layers the regional spelling and the language fallback: de-CH has no sharp s, missing keys come from English', function (): void {
    locSystemText('de', 'crm.street', 'Straße');

    $entries = $this->getJson(DICTIONARY_URL.'?locale=de-CH')->json('data.entries');

    expect($entries['crm.street'])->toBe('Strasse')
        ->and($this->getJson(DICTIONARY_URL.'?locale=de')->json('data.entries')['crm.street'])->toBe('Straße');
});

it('applies the override of a tenant over the system text and over the base language', function (): void {
    locText($this->tenant->id, 'de', 'invoicing.title', 'Faktura (Sprache)');
    locText($this->tenant->id, 'de-CH', 'crm.title', 'Adressen (exakt)');

    $entries = $this->getJson(DICTIONARY_URL.'?locale=de-CH')->json('data.entries');

    expect($entries['invoicing.title'])->toBe('Faktura (Sprache)')
        ->and($entries['crm.title'])->toBe('Adressen (exakt)');
});

it('keeps the overrides of a tenant invisible to another tenant and to guests, system texts are visible to all', function (): void {
    locText($this->tenant->id, 'de', 'invoicing.title', 'Nur Alfa');

    $own = $this->getJson(DICTIONARY_URL.'?locale=de-CH')->json('data.entries');

    locActor($this, 'owner', $this->otherTenant);
    $other = $this->getJson(DICTIONARY_URL.'?locale=de-CH')->json('data.entries');

    // A guest cannot select a tenant by guessing its id: the statement counts only for a signed-in member
    $this->app['auth']->forgetGuards();
    $guest = $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson(DICTIONARY_URL.'?locale=de-CH')->json('data.entries');

    expect($own['invoicing.title'])->toBe('Nur Alfa')
        ->and($other['invoicing.title'])->toBe('Rechnung')
        ->and($guest['invoicing.title'])->toBe('Rechnung')
        ->and($own['crm.title'])->toBe($other['crm.title'])->toBe('Kontakte');
});

it('rebuilds the dictionary when TranslationsChanged is dispatched', function (): void {
    $before = $this->getJson(DICTIONARY_URL.'?locale=en')->json('data');

    locSystemText('en', 'invoicing.footer', 'Thank you');
    // Still served from the versioned cache until the change is announced
    expect($this->getJson(DICTIONARY_URL.'?locale=en')->json('data.version_hash'))->toBe($before['version_hash']);

    Event::dispatch(new TranslationsChanged(null, 'en'));

    $after = $this->getJson(DICTIONARY_URL.'?locale=en')->json('data');

    expect($after['version_hash'])->not->toBe($before['version_hash'])
        ->and($after['entries']['invoicing.footer'])->toBe('Thank you');
});

it('invalidates only the dictionary of the tenant that changed', function (): void {
    locActor($this, 'owner', $this->otherTenant);
    $otherBefore = $this->getJson(DICTIONARY_URL.'?locale=en')->json('data.version_hash');

    locActAs($this, $this->owner, $this->tenant);
    $this->putJson('/api/v1/settings/translations/invoicing/title', ['locale' => 'en', 'value' => 'Bill'])->assertOk();

    expect($this->getJson(DICTIONARY_URL.'?locale=en')->json('data.entries')['invoicing.title'])->toBe('Bill');

    locActor($this, 'owner', $this->otherTenant);
    expect($this->getJson(DICTIONARY_URL.'?locale=en')->json('data.version_hash'))->toBe($otherBefore);
});

it('changes the hash after an override saved through the API so clients drop their cache', function (): void {
    $hash = $this->getJson(DICTIONARY_URL.'?locale=en')->json('data.version_hash');

    $this->putJson('/api/v1/settings/translations/invoicing/title', ['locale' => 'en', 'value' => 'Bill'])->assertOk();

    $changed = $this->getJson(DICTIONARY_URL.'?locale=en', ['If-None-Match' => '"'.$hash.'"'])->assertOk();

    expect($changed->json('data.version_hash'))->not->toBe($hash)
        ->and($changed->json('data.entries')['invoicing.title'])->toBe('Bill');
});

it('rejects an unsupported locale with a validation error', function (): void {
    $this->getJson(DICTIONARY_URL.'?locale=es-ES')->assertStatus(422)->assertJsonValidationErrors('locale');
    $this->getJson(DICTIONARY_URL)->assertStatus(422)->assertJsonValidationErrors('locale');
});

it('accepts the plain languages of the legacy clients', function (): void {
    expect($this->getJson(DICTIONARY_URL.'?locale=de')->assertOk()->json('data.entries')['invoicing.title'])->toBe('Rechnung');
});

it('logs a structured warning for a missing key resolved server side', function (): void {
    $logger = new class extends AbstractLogger
    {
        /** @var list<array{message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['message' => (string) $message, 'context' => $context];
        }
    };
    $this->app->instance(LoggerInterface::class, $logger);

    $resolver = app(TranslationFallbackResolver::class);

    expect($resolver->resolve('invoicing.nothing', Locale::from('it-CH'), $this->tenant->id))->toBe('invoicing.nothing')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toBe('i18n.missing_translation_key')
        ->and($logger->records[0]['context']['key'])->toBe('invoicing.nothing');
});

it('resolves a stored text through the database lookup with the full fallback chain', function (): void {
    locText($this->tenant->id, 'de', 'invoicing.title', 'Faktura');

    $resolver = app(TranslationFallbackResolver::class);
    $locale = Locale::from('de-CH');

    expect($resolver->resolve('invoicing.title', $locale, $this->tenant->id))->toBe('Faktura')
        ->and($resolver->resolve('invoicing.title', $locale, $this->otherTenant->id))->toBe('Rechnung')
        ->and($resolver->resolve('invoicing.subtitle', $locale, null))->toBe('Sales invoices');
});
