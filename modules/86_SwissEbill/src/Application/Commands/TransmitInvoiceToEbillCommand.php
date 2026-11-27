<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

/**
 * Queues the delivery of an issued invoice through the Swiss eBill network. With `supersedesId` it revises a rejected
 * transmission: the data is rebuilt from the current invoice and customer, and the new transmission points to the old one.
 */
final readonly class TransmitInvoiceToEbillCommand
{
    public function __construct(
        public string $invoiceId,
        public ?string $userId = null,
        public ?string $supersedesId = null,
        public string $source = 'user',
    ) {}
}
