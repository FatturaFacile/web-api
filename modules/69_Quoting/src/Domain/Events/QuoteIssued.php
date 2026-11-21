<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Events;

final readonly class QuoteIssued
{
    public function __construct(public string $quoteId, public ?string $customerEmail, public string $quoteNumber, public string $portalUrl, public ?string $pin) {}
}
