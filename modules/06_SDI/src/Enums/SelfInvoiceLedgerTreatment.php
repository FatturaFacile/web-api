<?php

declare(strict_types=1);

namespace Modules\SDI\Enums;

/**
 * What a document does to the ledger. `Integration` puts the same VAT on the sales and purchase side (Dr 1400 / Cr 2200),
 * `VatCost` charges VAT the tenant cannot recover to a cost (Dr 6975 / Cr 2200), `NoEntry` is a communication whose VAT
 * does not go through the periodic settlement.
 */
enum SelfInvoiceLedgerTreatment
{
    case Sale;
    case Integration;
    case VatCost;
    case NoEntry;
}
