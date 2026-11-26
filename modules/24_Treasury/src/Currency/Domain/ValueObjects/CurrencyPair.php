<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\ValueObjects;

/**
 * Base and quote: 1 base = rate quote. A document in a foreign currency uses the pair (document currency, functional currency).
 */
final readonly class CurrencyPair
{
    public function __construct(public CurrencyCode $base, public CurrencyCode $quote) {}

    public static function of(string $base, string $quote): self
    {
        return new self(CurrencyCode::of($base), CurrencyCode::of($quote));
    }

    public function inverse(): self
    {
        return new self($this->quote, $this->base);
    }

    public function isIdentity(): bool
    {
        return $this->base->equals($this->quote);
    }

    public function equals(self $other): bool
    {
        return $this->base->equals($other->base) && $this->quote->equals($other->quote);
    }

    public function key(): string
    {
        return $this->base->value.'/'.$this->quote->value;
    }
}
