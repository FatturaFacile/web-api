<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;

final class IssueCreditNoteAction
{
    public function __construct(
        private readonly InvoicingServiceInterface $invoicingService
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function execute(SalesInvoice $originalInvoice, array $data, array $items): SalesInvoice
    {
        return $this->invoicingService->issueCreditNote($originalInvoice, $data, $items);
    }
}
