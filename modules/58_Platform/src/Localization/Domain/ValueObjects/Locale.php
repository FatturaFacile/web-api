<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\ValueObjects;

use Modules\Platform\Localization\Domain\Enums\SupportedLocale;
use Modules\Platform\Localization\Domain\Exceptions\InvalidLocaleException;

/**
 * A validated locale identifier: one of the {@see SupportedLocale} values or, for dictionary lookups, one of the plain
 * languages the dictionaries are stored under (it, de, fr). The base language of de-CH is de.
 */
final readonly class Locale
{
    private const array PLAIN_LANGUAGES = ['it', 'de', 'fr'];

    private function __construct(private string $value) {}

    public static function from(string $value): self
    {
        $supported = SupportedLocale::tryFromLoose($value);

        if ($supported !== null) {
            return new self($supported->value);
        }

        $language = strtolower(trim($value));

        if (in_array($language, self::PLAIN_LANGUAGES, true)) {
            return new self($language);
        }

        throw InvalidLocaleException::unsupported($value);
    }

    public static function tryFrom(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        try {
            return self::from($value);
        } catch (InvalidLocaleException) {
            return null;
        }
    }

    public static function fromSupported(SupportedLocale $locale): self
    {
        return new self($locale->value);
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * The language without region: de-CH gives de.
     */
    public function language(): string
    {
        return strtolower(explode('-', $this->value)[0]);
    }

    public function isRegional(): bool
    {
        return str_contains($this->value, '-');
    }

    /**
     * The locale this one derives from: the plain language of a regional variant, itself otherwise.
     */
    public function base(): self
    {
        return new self($this->language());
    }

    public function supported(): ?SupportedLocale
    {
        return SupportedLocale::tryFrom($this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
