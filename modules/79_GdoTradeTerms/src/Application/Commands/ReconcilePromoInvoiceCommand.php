<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class ReconcilePromoInvoiceCommand
{
    /**
     * @param  array<int|string, mixed>  $invoice
     */
    public function __construct(
        public string $settlementId,
        public array $invoice,
        public ?string $userId,
    ) {}
}
