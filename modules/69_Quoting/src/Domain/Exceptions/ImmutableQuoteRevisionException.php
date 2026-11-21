<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Exceptions;

final class ImmutableQuoteRevisionException extends QuoteException
{
    public static function forQuote(string $quoteNumber): self
    {
        return new self("Quote revision {$quoteNumber} has been issued and is frozen: create a new revision to change it.");
    }
}
