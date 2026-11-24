<?php

declare(strict_types=1);

namespace Modules\Invoicing\Listeners;

use App\Enums\QuoteStatus;
use App\Enums\SalesOrderStatus;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

/**
 * Applies the outcome of an approval workflow to the quote, order or invoice it was started for. A document that is no
 * longer in a status the outcome can move it from is left alone (the state machines of the status enums decide).
 */
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
        if ($quote !== null) {
            if ($quote->status !== QuoteStatus::Approved->value && QuoteStatus::allows($quote->status, QuoteStatus::Approved)) {
                $this->quoteService->approveQuote($quote, 'workflow-engine');
            }

            return;
        }

        $order = SalesOrder::where('workflow_instance_id', $instanceId)->orWhere('id', $instanceId)->first();
        if ($order !== null) {
            if ($order->status !== SalesOrderStatus::Approved->value && SalesOrderStatus::allows($order->status, SalesOrderStatus::Approved)) {
                $this->orderService->approveOrder($order, 'workflow-engine');
            }

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

        SalesQuote::where('workflow_instance_id', $instanceId)->orWhere('id', $instanceId)->get()
            ->filter(static fn (SalesQuote $quote): bool => $quote->status !== QuoteStatus::Rejected->value && QuoteStatus::allows($quote->status, QuoteStatus::Rejected))
            ->each(fn (SalesQuote $quote): SalesQuote => $this->quoteService->rejectQuote($quote, 'workflow-engine'));

        SalesOrder::where('workflow_instance_id', $instanceId)->orWhere('id', $instanceId)->get()
            ->filter(static fn (SalesOrder $order): bool => $order->status !== SalesOrderStatus::Rejected->value && SalesOrderStatus::allows($order->status, SalesOrderStatus::Rejected))
            ->each(static fn (SalesOrder $order): bool => $order->update(['status' => SalesOrderStatus::Rejected->value]));

        SalesInvoice::where(fn ($query) => $query->where('workflow_instance_id', $instanceId)->orWhere('id', $instanceId))
            ->where('status', '!=', 'posted')
            ->update(['status' => 'rejected']);
    }
}
