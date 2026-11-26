<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * An ISO 4217 alphabetic currency code.
 */
final readonly class CurrencyCode
{
    private function __construct(public string $value) {}

    public static function of(string $code): self
    {
        $normalized = strtoupper(trim($code));

        if (preg_match('/^[A-Z]{3}$/', $normalized) !== 1) {
            throw new InvalidArgumentException("Invalid ISO 4217 currency code [{$code}].");
        }

        return new self($normalized);
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
