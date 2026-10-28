<?php

declare(strict_types=1);

namespace Modules\Invoicing\Listeners;

use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final readonly class WorkflowApprovalInvoicingListener
{
    public function __construct(
        private SalesQuoteServiceInterface $quoteService,
        private SalesOrderServiceInterface $orderService,
        private InvoicingServiceInterface $invoicingService
    ) {}

    public function handleWorkflowApproved(WorkflowInstanceCompletedEvent $event): void
    {
        $entityType = $event->entityType;
        $entityId = $event->entityId;

        if ($entityType === 'sales_quote') {
            $quote = SalesQuote::find($entityId);
            if ($quote !== null && $quote->status !== 'approved') {
                $this->quoteService->approveQuote($quote, 'workflow-engine');
            }
        } elseif ($entityType === 'sales_order') {
            $order = SalesOrder::find($entityId);
            if ($order !== null && $order->status !== 'approved') {
                $this->orderService->approveOrder($order, 'workflow-engine');
            }
        } elseif ($entityType === 'sales_invoice') {
            $invoice = SalesInvoice::find($entityId);
            if ($invoice !== null && $invoice->status !== 'approved') {
                $this->invoicingService->approveInvoice($invoice, 'workflow-engine');
            }
        }
    }

    public function handleWorkflowRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $entityType = $event->entityType;
        $entityId = $event->entityId;

        if ($entityType === 'sales_quote') {
            SalesQuote::where('id', $entityId)->update(['status' => 'rejected']);
        } elseif ($entityType === 'sales_order') {
            SalesOrder::where('id', $entityId)->update(['status' => 'rejected']);
        } elseif ($entityType === 'sales_invoice') {
            SalesInvoice::where('id', $entityId)->update(['status' => 'rejected']);
        }
    }
}
