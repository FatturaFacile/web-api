<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\Auth;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;

final class PostInvoiceAction
{
    public function __construct(
        private readonly InvoicingServiceInterface $invoicingService
    ) {}

    public function execute(SalesInvoice $invoice, string $userId): SalesInvoice
    {
        return $this->invoicingService->postInvoice($invoice, $userId);
    }

    /**
     * Emission of a document of the legacy sales flow, which shares the table of this module.
     */
    public function issue(string $invoiceId): SalesInvoice
    {
        $invoice = SalesInvoice::withoutGlobalScope(TenantScope::class)->whereKey($invoiceId)->firstOrFail();

        return $this->invoicingService->postInvoice($invoice, (string) Auth::id());
    }
}
