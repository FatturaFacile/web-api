<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\Enums;

/**
 * Kind of business document an extraction job turns into once the accountant confirms it.
 */
enum DocumentCategory: string
{
    case VendorInvoice = 'vendor_invoice';
    case ExpenseReceipt = 'expense_receipt';
    case TransportDdt = 'transport_ddt';
    case VendorQuote = 'vendor_quote';
}
