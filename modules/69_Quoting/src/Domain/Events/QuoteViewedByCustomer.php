<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Events;

final readonly class QuoteViewedByCustomer
{
    public function __construct(public string $quoteId, public ?string $ip) {}
}
