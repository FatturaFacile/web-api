<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Sources;

use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\LiquidityPlanning\Support\Flow;

/**
 * The certain components: the open customer invoices (accounts receivable) and supplier invoices (accounts payable) on their
 * due date. Credit notes and proformas are left out; an overdue invoice falls on the first day and the average delay of the
 * customers (setting) postpones the collections.
 */
final class LedgerDueDatesSource implements FlowSource
{
    public function flows(SourceContext $context): array
    {
        $flows = [];
        $delay = $context->settings->collection_behavior_days;
        $cutoff = $context->end->subDays($delay)->toDateString();

        $receivables = Invoice::query()
            ->where('tenant_id', $context->tenantId)
            ->where('currency', $context->currency)
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->where('payment_status', '!=', 'paid')
            ->where(fn ($query) => $query->whereNull('due_date')->orWhereDate('due_date', '<=', $cutoff))
            ->get();

        foreach ($receivables as $invoice) {
            $open = $invoice->outstandingAmount();

            if (Decimal::isPositive($open)) {
                $flows[] = new Flow($context->onOrAfterStart($invoice->due_date ?? $invoice->date)->addDays($delay), Flow::Inflow, Flow::Certain, 'receivable', (string) $invoice->invoice_number, Decimal::round2($open), 'lpApi.category.receivable');
            }
        }

        $payables = PassiveInvoice::query()
            ->where('tenant_id', $context->tenantId)
            ->where('currency', $context->currency)
            ->where('document_type', '!=', 'TD04')
            ->where(fn ($query) => $query->whereNull('payment_status')->orWhere('payment_status', '!=', 'paid'))
            ->where(fn ($query) => $query->whereNull('due_date')->orWhereDate('due_date', '<=', $context->end->toDateString()))
            ->get();

        foreach ($payables as $invoice) {
            $open = Decimal::round2(Decimal::sub($invoice->amountDue(), (string) ($invoice->total_paid ?? '0')));

            if (Decimal::isPositive($open)) {
                $flows[] = new Flow($context->onOrAfterStart($invoice->due_date ?? $invoice->date), Flow::Outflow, Flow::Certain, 'payable', (string) $invoice->invoice_number, $open, 'lpApi.category.payable');
            }
        }

        return $flows;
    }
}
