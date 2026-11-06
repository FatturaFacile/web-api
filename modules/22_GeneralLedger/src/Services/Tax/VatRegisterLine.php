<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

/**
 * One rate of one document of a VAT register. Amounts are signed: credit notes are negative.
 */
final readonly class VatRegisterLine
{
    public function __construct(
        public string $journalEntryId,
        public string $entryNumber,
        public string $postingDate,
        public string $documentDate,
        public ?string $documentNumber,
        public ?string $sourceDocumentType,
        public ?string $partnerId,
        public string $taxable,
        public string $rate,
        public string $tax,
        public ?string $treatment,
    ) {}
}
