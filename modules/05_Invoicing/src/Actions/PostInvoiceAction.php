<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

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
}
