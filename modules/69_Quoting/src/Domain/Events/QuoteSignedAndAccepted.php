<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Events;

final readonly class QuoteSignedAndAccepted
{
    public function __construct(public string $quoteId, public string $envelopeId) {}
}
