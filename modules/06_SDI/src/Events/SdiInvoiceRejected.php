<?php

declare(strict_types=1);

namespace Modules\SDI\Events;

/**
 * The SDI sent a rejection notice (NS) for an outbound electronic invoice: the file was discarded and must be corrected
 * and resubmitted within the resubmission deadline.
 */
final readonly class SdiInvoiceRejected
{
    /**
     * @param  list<array{code: string, description: string, line_reference: ?string}>  $errors
     */
    public function __construct(
        public string $tenantId,
        public string $fpaDocumentId,
        public ?string $invoiceId,
        public string $documentNumber,
        public array $errors,
    ) {}
}
