<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;

final class CreateInvoiceFromOrderAction
{
    public function __construct(
        private readonly InvoicingServiceInterface $invoicingService
    ) {}

    /**
     * @param array<string, mixed> $overrides
     */
    public function execute(SalesOrder $order, array $overrides = []): SalesInvoice
    {
        return $this->invoicingService->createInvoiceFromOrder($order, $overrides);
    }
}
