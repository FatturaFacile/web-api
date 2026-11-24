<?php

declare(strict_types=1);

namespace Modules\Procurement\Enums;

/**
 * Why an invoice or one of its lines does not match. The values are translation keys suffixes (RES.V.<value>).
 */
enum MatchDiscrepancyReason: string
{
    case NoPurchaseOrder = 'no_purchase_order';
    case UnmatchedLine = 'unmatched_line';
    case PriceAboveTolerance = 'price_above_tolerance';
    case QuantityAboveReceived = 'quantity_above_received';
    case QuantityAboveOrdered = 'quantity_above_ordered';
    case NotReceived = 'not_received';
    case DuplicateBilling = 'duplicate_billing';
    case CurrencyMismatch = 'currency_mismatch';
    case HeaderAboveTolerance = 'header_above_tolerance';
}
