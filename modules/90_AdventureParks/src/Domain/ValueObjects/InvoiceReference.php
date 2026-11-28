<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\ValueObjects;

/**
 * The electronic invoice created for a school booking: its id and its number in the invoicing module.
 */
final readonly class InvoiceReference
{
    public function __construct(public string $invoiceId, public string $invoiceNumber) {}
}
