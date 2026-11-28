<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Platform\Localization\Domain\Enums\SupportedLocale;

/**
 * The locale configuration of a tenant/company: the languages it works in, the default one and the optional overrides of
 * the number and date conventions of the locale.
 */
final readonly class LocaleSettings
{
    /**
     * @param  list<SupportedLocale>  $activeLocales
     * @param  array{decimal_separator: string, thousands_separator: string}|null  $numberFormat
     */
    public function __construct(
        public array $activeLocales,
        public SupportedLocale $defaultLocale,
        public ?array $numberFormat = null,
        public ?string $dateFormat = null,
    ) {
        if ($activeLocales === []) {
            throw new InvalidArgumentException('At least one locale must be active.');
        }

        if (! in_array($defaultLocale, $activeLocales, true)) {
            throw new InvalidArgumentException('The default locale must be one of the active locales.');
        }

        if ($numberFormat !== null) {
            new NumberFormat($numberFormat['decimal_separator'], $numberFormat['thousands_separator']);
        }

        if ($dateFormat !== null) {
            new DateFormat($dateFormat);
        }
    }

    /**
     * The defaults of a company by country: Swiss companies work in the three Swiss languages, Italian ones in Italian.
     */
    public static function defaultFor(?string $country): self
    {
        return match (strtoupper((string) $country)) {
            'CH' => new self([SupportedLocale::ItCh, SupportedLocale::DeCh, SupportedLocale::FrCh, SupportedLocale::En], SupportedLocale::ItCh),
            'IT' => new self([SupportedLocale::ItIt, SupportedLocale::En], SupportedLocale::ItIt),
            default => new self([SupportedLocale::En, SupportedLocale::ItIt], SupportedLocale::En),
        };
    }

    public function numberFormatFor(SupportedLocale $locale): NumberFormat
    {
        return NumberFormat::forLocaleWithOverride($locale, $this->numberFormat);
    }

    public function dateFormatFor(SupportedLocale $locale): DateFormat
    {
        return DateFormat::forLocaleWithOverride($locale, $this->dateFormat);
    }

    /**
     * @return array{active_locales: list<string>, default_locale: string, number_format: array{decimal_separator: string, thousands_separator: string}|null, date_format: string|null}
     */
    public function toArray(): array
    {
        return [
            'active_locales' => array_map(static fn (SupportedLocale $locale): string => $locale->value, $this->activeLocales),
            'default_locale' => $this->defaultLocale->value,
            'number_format' => $this->numberFormat,
            'date_format' => $this->dateFormat,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $country): self
    {
        $fallback = self::defaultFor($country);
        $active = [];

        foreach ((array) ($data['active_locales'] ?? []) as $value) {
            $locale = SupportedLocale::tryFrom((string) $value);

            if ($locale !== null) {
                $active[$locale->value] = $locale;
            }
        }

        $active = array_values($active);

        if ($active === []) {
            return $fallback;
        }

        $default = SupportedLocale::tryFrom((string) ($data['default_locale'] ?? '')) ?? $active[0];
        $number = $data['number_format'] ?? null;

        return new self(
            $active,
            in_array($default, $active, true) ? $default : $active[0],
            is_array($number) && isset($number['decimal_separator'], $number['thousands_separator']) ? ['decimal_separator' => (string) $number['decimal_separator'], 'thousands_separator' => (string) $number['thousands_separator']] : null,
            isset($data['date_format']) && is_string($data['date_format']) && $data['date_format'] !== '' ? $data['date_format'] : null,
        );
    }
}
