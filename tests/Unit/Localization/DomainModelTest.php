<?php

declare(strict_types=1);

use Modules\Platform\Localization\Application\Data\DictionaryData;
use Modules\Platform\Localization\Domain\Entities\TranslationCatalog;
use Modules\Platform\Localization\Domain\Entities\TranslationEntry;
use Modules\Platform\Localization\Domain\Enums\SupportedLocale;
use Modules\Platform\Localization\Domain\Enums\TranslatableResource;
use Modules\Platform\Localization\Domain\Events\TranslationsChanged;
use Modules\Platform\Localization\Domain\Exceptions\InvalidLocaleException;
use Modules\Platform\Localization\Domain\Exceptions\InvalidTranslationKeyException;
use Modules\Platform\Localization\Domain\Exceptions\LockedTranslationException;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Modules\Platform\Localization\Domain\ValueObjects\LocaleSettings;
use Modules\Platform\Localization\Domain\ValueObjects\TranslationKey;

it('validates locales against the supported ones and extracts the base language', function (): void {
    expect(Locale::from('de-CH')->value())->toBe('de-CH')
        ->and(Locale::from('de_ch')->value())->toBe('de-CH')
        ->and(Locale::from('de-CH')->language())->toBe('de')
        ->and(Locale::from('de-CH')->base()->value())->toBe('de')
        ->and(Locale::from('de-CH')->isRegional())->toBeTrue()
        ->and(Locale::from('fr')->isRegional())->toBeFalse()
        ->and(Locale::from('en')->base()->value())->toBe('en')
        ->and(Locale::from('it-IT')->supported())->toBe(SupportedLocale::ItIt)
        ->and(Locale::from('de')->supported())->toBeNull()
        ->and(Locale::tryFrom('es'))->toBeNull()
        ->and(Locale::from('de-CH')->equals(Locale::fromSupported(SupportedLocale::DeCh)))->toBeTrue();

    expect(fn () => Locale::from('es-ES'))->toThrow(InvalidLocaleException::class);
});

it('validates the format of a translation key', function (): void {
    $key = TranslationKey::from('invoicing.list.title');

    expect($key->group())->toBe('invoicing')
        ->and($key->name())->toBe('list.title')
        ->and(TranslationKey::fromParts('NAV', 'BEEKEEPING_DASHBOARD')->value())->toBe('NAV.BEEKEEPING_DASHBOARD')
        ->and(TranslationKey::isValid('a.b'))->toBeTrue()
        ->and(TranslationKey::isValid('nogroup'))->toBeFalse()
        ->and(TranslationKey::isValid('a..b'))->toBeFalse()
        ->and(TranslationKey::isValid('a.b c'))->toBeFalse()
        ->and(TranslationKey::isValid('.a'))->toBeFalse()
        ->and(TranslationKey::isValid('a.'))->toBeFalse()
        ->and(TranslationKey::isValid('a.'.str_repeat('x', 260)))->toBeFalse();

    expect(fn () => TranslationKey::from('nogroup'))->toThrow(InvalidTranslationKeyException::class);
});

it('keeps the locked flag of a catalog entry and refuses to change it', function (): void {
    $catalog = new TranslationCatalog(null, Locale::from('de'), [
        new TranslationEntry(TranslationKey::from('statutory.ch.vat_suffix'), 'MWST', isLocked: true),
        new TranslationEntry(TranslationKey::from('invoicing.title'), 'Rechnung'),
    ]);

    expect(fn () => $catalog->upsert(TranslationKey::from('statutory.ch.vat_suffix'), 'X'))->toThrow(LockedTranslationException::class)
        ->and($catalog->upsert(TranslationKey::from('invoicing.title'), 'Faktura')->value())->toBe('Faktura');
});

it('refuses a tenant override of a key the system locks', function (): void {
    $catalog = new TranslationCatalog('tenant-a', Locale::from('de-CH'), [], ['statutory.ch.vat_suffix']);

    expect($catalog->isLocked(TranslationKey::from('statutory.ch.vat_suffix')))->toBeTrue()
        ->and(fn () => $catalog->upsert(TranslationKey::from('statutory.ch.vat_suffix'), 'X'))->toThrow(LockedTranslationException::class)
        ->and($catalog->changedEntries())->toBe([]);
});

it('records a TranslationsChanged event only for an effective change', function (): void {
    $catalog = new TranslationCatalog('tenant-a', Locale::from('de-CH'), [new TranslationEntry(TranslationKey::from('invoicing.title'), 'Rechnung')]);

    $catalog->upsert(TranslationKey::from('invoicing.title'), 'Rechnung');
    expect($catalog->pullEvents())->toBe([]);

    $catalog->upsert(TranslationKey::from('invoicing.title'), 'Faktura');
    $catalog->upsert(TranslationKey::from('invoicing.subtitle'), 'Untertitel');
    $events = $catalog->pullEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TranslationsChanged::class)
        ->and($events[0]->tenantId)->toBe('tenant-a')
        ->and($events[0]->locale)->toBe('de-CH')
        ->and($catalog->changedEntries())->toHaveCount(2)
        ->and($catalog->pullEvents())->toBe([]);
});

it('builds locale settings with the defaults of each country', function (): void {
    $swiss = LocaleSettings::defaultFor('CH');
    $italian = LocaleSettings::defaultFor('IT');

    expect($swiss->defaultLocale)->toBe(SupportedLocale::ItCh)
        ->and($swiss->activeLocales)->toContain(SupportedLocale::DeCh, SupportedLocale::FrCh)
        ->and($italian->defaultLocale)->toBe(SupportedLocale::ItIt)
        ->and(LocaleSettings::defaultFor('DE')->defaultLocale)->toBe(SupportedLocale::En);
});

it('refuses locale settings whose default language is not active', function (): void {
    expect(fn () => new LocaleSettings([SupportedLocale::En], SupportedLocale::DeCh))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LocaleSettings([], SupportedLocale::En))->toThrow(InvalidArgumentException::class);
});

it('reads stored locale settings and ignores what is not supported', function (): void {
    $settings = LocaleSettings::fromArray([
        'active_locales' => ['de-CH', 'xx', 'en'],
        'default_locale' => 'zz',
        'number_format' => ['decimal_separator' => ',', 'thousands_separator' => '.'],
        'date_format' => 'dd/MM/yyyy',
    ], 'CH');

    expect($settings->activeLocales)->toBe([SupportedLocale::DeCh, SupportedLocale::En])
        ->and($settings->defaultLocale)->toBe(SupportedLocale::DeCh)
        ->and($settings->numberFormatFor(SupportedLocale::DeCh)->decimalSeparator)->toBe(',')
        ->and($settings->dateFormatFor(SupportedLocale::DeCh)->pattern)->toBe('dd/MM/yyyy')
        ->and(LocaleSettings::fromArray([], 'IT')->defaultLocale)->toBe(SupportedLocale::ItIt)
        ->and(LocaleSettings::fromArray([], 'IT')->numberFormatFor(SupportedLocale::ItIt)->thousandsSeparator)->toBe('.');
});

it('maps each translatable resource to its tables and fields', function (): void {
    foreach (TranslatableResource::cases() as $resource) {
        expect($resource->fields())->not->toBeEmpty()
            ->and($resource->translationTable())->toEndWith('_translations')
            ->and($resource->foreignKey())->toEndWith('_id')
            ->and(class_exists($resource->modelClass()))->toBeTrue()
            ->and(app($resource->modelClass())->getTable())->toBe($resource->table());
    }

    expect(TranslatableResource::slugs())->toBe(['products', 'product-categories', 'payment-terms', 'shipping-reasons', 'units-of-measure', 'standard-texts'])
        ->and(TranslatableResource::PaymentTerm->fields())->toBe(['name', 'invoice_clause']);
});

it('matches the version hash of a dictionary against If-None-Match', function (): void {
    $dictionary = new DictionaryData('de-CH', 'abc123', ['a.b' => 'c']);

    expect($dictionary->etag())->toBe('"abc123"')
        ->and($dictionary->matches('"abc123"'))->toBeTrue()
        ->and($dictionary->matches('W/"abc123"'))->toBeTrue()
        ->and($dictionary->matches('"zzz", "abc123"'))->toBeTrue()
        ->and($dictionary->matches('"other"'))->toBeFalse()
        ->and($dictionary->matches(null))->toBeFalse()
        ->and($dictionary->matches(''))->toBeFalse();
});
