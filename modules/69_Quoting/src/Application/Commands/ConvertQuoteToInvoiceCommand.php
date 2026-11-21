<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Commands;

final readonly class ConvertQuoteToInvoiceCommand
{
    /** @param string|null $downPaymentPercent null = invoice the whole quote; otherwise a deposit of that percentage (0-100, exclusive of 0) */
    public function __construct(
        public string $quoteId,
        public ?string $userId,
        public ?string $downPaymentPercent = null,
    ) {}
}
