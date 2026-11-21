<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Events;

final readonly class QuoteOptionsUpdatedByCustomer
{
    /** @param array<string, bool> $selections */
    public function __construct(public string $quoteId, public array $selections) {}
}
