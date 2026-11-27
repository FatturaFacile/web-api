<?php

declare(strict_types=1);

namespace App\Contracts\Banking;

use App\Models\Invoice;

/**
 * Lets a module claim the bank entries of its own payment channel that carry no QR / SCOR reference (an eBill paid through
 * the e-banking quotes the EndToEndId or the network transaction id instead). Bound optionally; ImportBankPayments asks every
 * bound resolver once the standard matching found nothing.
 */
interface InvoiceReferenceResolverInterface
{
    /**
     * @param  string  $text  reference, EndToEndId and remittance text of the bank entry, joined
     */
    public function resolve(string $text): ?Invoice;
}
