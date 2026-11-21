<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Events;

final readonly class QuoteOtpRequested
{
    public function __construct(public string $quoteId, public string $channel, public string $destination, public string $code, public string $quoteNumber) {}
}
