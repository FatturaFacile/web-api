<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Contracts;

use Modules\AdventureParks\Domain\ValueObjects\InvoiceReference;
use Modules\AdventureParks\Models\SchoolBooking;

/**
 * Port towards the invoicing and FatturaPA engine: the module describes what to bill (lines, split payment, CIG / CUP, IPA office
 * code) and the adapter creates the invoice there, so the XML itself is never built here.
 */
interface SchoolInvoicePort
{
    /**
     * @param  list<array{description: string, quantity: int, unit_price: string, tax_rate: string}>  $lines
     */
    public function createDraft(SchoolBooking $booking, array $lines, string $wording, ?string $userId): InvoiceReference;
}
