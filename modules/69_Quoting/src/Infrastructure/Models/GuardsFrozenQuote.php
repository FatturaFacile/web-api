<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Models;

use Modules\Quoting\Domain\Exceptions\ImmutableQuoteRevisionException;

/**
 * Sections and lines of a frozen revision cannot be created, changed or removed.
 */
trait GuardsFrozenQuote
{
    public static function bootGuardsFrozenQuote(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::{$event}(static function (self $row): void {
                $quote = CpqQuote::withoutGlobalScopes()->find($row->quote_id);

                if ($quote instanceof CpqQuote && $quote->isFrozen()) {
                    throw ImmutableQuoteRevisionException::forQuote($quote->quote_number);
                }
            });
        }
    }
}
