<?php

declare(strict_types=1);

use Modules\Platform\Localization\Domain\Enums\SupportedLocale;
use Modules\Platform\Localization\Domain\Services\DocumentLocaleResolver;
use Modules\Platform\Localization\Domain\Services\LocaleResolver;

it('resolves the user preference first', function (): void {
    expect((new LocaleResolver)->resolve('fr-CH', 'de-CH,de;q=0.9', 'it-CH'))->toBe(SupportedLocale::FrCh);
});

it('resolves the Accept-Language header when the user has no preference', function (): void {
    expect((new LocaleResolver)->resolve(null, 'de-CH,de;q=0.9,en;q=0.8', 'it-CH'))->toBe(SupportedLocale::DeCh);
});

it('honours the q-values of Accept-Language and skips unsupported languages', function (): void {
    expect((new LocaleResolver)->fromAcceptLanguage('es;q=1.0, en;q=0.4, fr-CH;q=0.9'))->toBe(SupportedLocale::FrCh)
        ->and((new LocaleResolver)->fromAcceptLanguage('es, pt;q=0.5'))->toBeNull()
        ->and((new LocaleResolver)->fromAcceptLanguage('*'))->toBeNull()
        ->and((new LocaleResolver)->fromAcceptLanguage(null))->toBeNull();
});

it('falls back to the tenant default and finally to English', function (): void {
    expect((new LocaleResolver)->resolve(null, null, 'it-IT'))->toBe(SupportedLocale::ItIt)
        ->and((new LocaleResolver)->resolve(null, 'ja', null))->toBe(SupportedLocale::En)
        ->and((new LocaleResolver)->resolve('xx', '', 'zz'))->toBe(SupportedLocale::En);
});

it('maps a bare language to the Swiss variant, or the Italian one for an Italian company', function (): void {
    $resolver = new LocaleResolver;

    expect($resolver->fromPreference('it'))->toBe(SupportedLocale::ItCh)
        ->and($resolver->fromPreference('it', 'IT'))->toBe(SupportedLocale::ItIt)
        ->and($resolver->fromPreference('it-IT'))->toBe(SupportedLocale::ItIt)
        ->and($resolver->fromPreference('de_ch'))->toBe(SupportedLocale::DeCh)
        ->and($resolver->fromPreference('fr'))->toBe(SupportedLocale::FrCh)
        ->and($resolver->fromPreference('en-GB'))->toBe(SupportedLocale::En);
});

it('prints a document in its frozen locale first', function (): void {
    expect((new DocumentLocaleResolver)->resolve('fr-CH', 'de-CH', SupportedLocale::ItCh))->toBe(SupportedLocale::FrCh);
});

it('uses the document locale of the contact when the document has none', function (): void {
    expect((new DocumentLocaleResolver)->resolve(null, 'de-CH', SupportedLocale::ItCh))->toBe(SupportedLocale::DeCh)
        ->and((new DocumentLocaleResolver)->resolve('', 'de', SupportedLocale::ItIt))->toBe(SupportedLocale::DeCh);
});

it('uses the document locale of the issuing company as the last resort', function (): void {
    expect((new DocumentLocaleResolver)->resolve(null, null, SupportedLocale::ItIt))->toBe(SupportedLocale::ItIt)
        ->and((new DocumentLocaleResolver)->resolve(null, 'xx', 'fr-CH'))->toBe(SupportedLocale::FrCh)
        ->and((new DocumentLocaleResolver)->resolve(null, null, 'nonsense'))->toBe(SupportedLocale::En);
});

it('breaks the Italian tie with the country of the issuing company', function (): void {
    expect((new DocumentLocaleResolver)->resolve(null, 'it', SupportedLocale::En, 'IT'))->toBe(SupportedLocale::ItIt)
        ->and((new DocumentLocaleResolver)->resolve(null, 'it', SupportedLocale::En, 'CH'))->toBe(SupportedLocale::ItCh);
});
