<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Events;

/**
 * Goods were extracted from an IVA warehouse and the TD22 / TD23 self-invoice was created.
 */
final readonly class VatDepotExtractionEmitted
{
    public function __construct(
        public string $registerEntryId,
        public string $authorizationId,
        public string $selfInvoiceId,
        public string $documentType,
        public float $vatAmount,
    ) {}
}
