<?php

declare(strict_types=1);

namespace Modules\Invoicing\Contracts;

use Modules\Invoicing\Models\CustomerLedgerEntry;
use Modules\Invoicing\Models\SalesInvoice;

interface CustomerLedgerServiceInterface
{
    public function recordInvoiceEntry(SalesInvoice $invoice, string $userId): CustomerLedgerEntry;

    public function recordCreditNoteEntry(SalesInvoice $creditNote, string $userId): CustomerLedgerEntry;

    public function recordPaymentEntry(
        string $customerId,
        float $amount,
        string $paymentReference,
        string $tenantId,
        ?string $invoiceId = null
    ): CustomerLedgerEntry;

    /**
     * @return array{
     *     current: float,
     *     days_1_30: float,
     *     days_31_60: float,
     *     days_61_90: float,
     *     days_90_plus: float,
     *     total_overdue: float,
     *     total_outstanding: float
     * }
     */
    public function calculateAging(string $customerId, string $tenantId): array;
}
