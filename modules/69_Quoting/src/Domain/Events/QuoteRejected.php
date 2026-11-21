<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Events;

final readonly class QuoteRejected
{
    public function __construct(public string $quoteId, public string $reason) {}
}
