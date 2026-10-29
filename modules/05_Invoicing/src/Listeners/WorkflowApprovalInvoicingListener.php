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
        $instanceId = $event->instanceId;

        $quote = SalesQuote::where('workflow_instance_id', $instanceId)->orWhere('id', $instanceId)->first();
        if ($quote !== null && $quote->status !== 'approved') {
            $this->quoteService->approveQuote($quote, 'workflow-engine');

            return;
        }

        $order = SalesOrder::where('workflow_instance_id', $instanceId)->orWhere('id', $instanceId)->first();
        if ($order !== null && $order->status !== 'approved') {
            $this->orderService->approveOrder($order, 'workflow-engine');

            return;
        }

        $invoice = SalesInvoice::where('workflow_instance_id', $instanceId)->orWhere('id', $instanceId)->first();
        if ($invoice !== null && $invoice->status !== 'approved') {
            $this->invoicingService->approveInvoice($invoice, 'workflow-engine');
        }
    }

    public function handleWorkflowRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $instanceId = $event->instanceId;

        SalesQuote::where('workflow_instance_id', $instanceId)->orWhere('id', $instanceId)->update(['status' => 'rejected']);
        SalesOrder::where('workflow_instance_id', $instanceId)->orWhere('id', $instanceId)->update(['status' => 'rejected']);
        SalesInvoice::where('workflow_instance_id', $instanceId)->orWhere('id', $instanceId)->update(['status' => 'rejected']);
    }
}
