<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Models\PassiveInvoice;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\DTOs\MatchResult;
use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Enums\MatchTrigger;
use Modules\Procurement\Events\InvoiceMatchBlockedEvent;
use Modules\Procurement\Events\InvoiceMatchClearedEvent;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\InvoiceMatchingRun;
use Modules\Procurement\Models\PurchaseOrder;

/**
 * Runs the matching of supplier invoices and stores the outcome: the run with its line-by-line comparison, the payment
 * block (BLOCKED_DISCREPANCY) or the release into the payment schedule, the audit record and the events.
 */
final class InvoiceMatchingService
{
    public function __construct(
        private readonly InvoiceMatchingEngine $engine,
        private readonly PurchaseOrderResolver $orders,
        private readonly ProcurementAuditLedgerService $auditLedger,
        private readonly EventDispatcher $dispatcher,
    ) {}

    /**
     * Matches one invoice. An automatic or batch run keeps a decision a person already took (override or resolution);
     * a manual run or a re-match evaluates again with the current order and receipt data.
     *
     * @throws ProcurementRuleViolation when the invoice or the named purchase order does not exist for the supplier
     */
    public function run(string $invoiceId, ?string $purchaseOrderId = null, MatchTrigger $trigger = MatchTrigger::Manual, ?string $actorId = null): InvoiceMatchingRun
    {
        return DB::transaction(function () use ($invoiceId, $purchaseOrderId, $trigger, $actorId): InvoiceMatchingRun {
            $invoice = PassiveInvoice::query()->with('lines')->whereKey($invoiceId)->lockForUpdate()->first();

            if ($invoice === null) {
                throw new ProcurementRuleViolation('The supplier invoice does not exist in the active company.', 'passive_invoice_id');
            }

            $current = $this->currentRun($invoiceId);

            if ($current !== null && in_array($trigger, [MatchTrigger::Auto, MatchTrigger::Batch], true) && in_array($current->status, [InvoiceMatchStatus::OverrideApproved, InvoiceMatchStatus::Resolved], true)) {
                return $current;
            }

            $resolved = $this->orders->resolve($invoice, $purchaseOrderId);

            if ($purchaseOrderId !== null && $resolved['order'] === null) {
                throw new ProcurementRuleViolation('The purchase order does not exist for the supplier of the invoice.', 'purchase_order_id');
            }

            $order = $resolved['order'];
            $result = $order !== null ? $this->engine->evaluate($invoice, $order) : $this->engine->evaluateWithoutOrder($invoice);

            return $this->store($invoice, $order, $result, $trigger, $resolved['source'], $resolved['value'], $actorId);
        });
    }

    /**
     * Matches the invoices that have no current run. Credit notes are not matched. Invoices without any resolvable
     * purchase order are skipped unless `$includeUnreferenced` is set (they are then blocked for discrepancy).
     *
     * @return array{processed: int, matched: int, blocked: int, skipped: int, run_ids: list<string>}
     */
    public function runBatch(?string $supplierId, int $limit, bool $includeUnreferenced = false, ?string $actorId = null): array
    {
        $summary = ['processed' => 0, 'matched' => 0, 'blocked' => 0, 'skipped' => 0, 'run_ids' => []];

        $invoices = PassiveInvoice::query()
            ->with('lines')
            ->where('document_type', '!=', 'TD04')
            ->whereNotIn('id', InvoiceMatchingRun::query()->where('is_current', true)->select('passive_invoice_id'))
            ->when($supplierId !== null, static fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderBy('date')
            ->limit(max(1, min(500, $limit)))
            ->get();

        foreach ($invoices as $invoice) {
            if (! $includeUnreferenced && $this->orders->resolve($invoice)['order'] === null) {
                $summary['skipped']++;

                continue;
            }

            $run = $this->run((string) $invoice->id, null, MatchTrigger::Batch, $actorId);
            $summary['processed']++;
            $summary[$run->status->isBlocking() ? 'blocked' : 'matched']++;
            $summary['run_ids'][] = (string) $run->id;
        }

        return $summary;
    }

    public function currentRun(string $invoiceId): ?InvoiceMatchingRun
    {
        return InvoiceMatchingRun::query()->where('passive_invoice_id', $invoiceId)->where('is_current', true)->latest('evaluated_at')->first();
    }

    /**
     * Releases the payment block of an invoice whose run was resolved (resolution, override) and tells the others.
     */
    public function release(InvoiceMatchingRun $run, InvoiceMatchStatus $status): void
    {
        $invoice = PassiveInvoice::query()->whereKey($run->passive_invoice_id)->lockForUpdate()->first();
        $invoice?->releaseMatchBlock($status->value);

        $run->forceFill(['status' => $status, 'released_at' => now()])->save();
        $this->announceCleared($run);
    }

    private function store(PassiveInvoice $invoice, ?PurchaseOrder $order, MatchResult $result, MatchTrigger $trigger, string $source, ?string $reference, ?string $actorId): InvoiceMatchingRun
    {
        $tenantId = (string) $invoice->tenant_id;

        InvoiceMatchingRun::query()->where('passive_invoice_id', $invoice->id)->where('is_current', true)->update(['is_current' => false]);

        $run = InvoiceMatchingRun::create([
            'tenant_id' => $tenantId,
            'company_id' => $order?->company_id,
            'passive_invoice_id' => $invoice->id,
            'purchase_order_id' => $order?->id,
            'supplier_id' => $invoice->supplier_id,
            'match_type' => $result->type->value,
            'status' => $result->status->value,
            'trigger' => $trigger->value,
            'reference_source' => $source,
            'reference_value' => $reference,
            'currency' => $result->currency,
            'ordered_total' => $result->orderedTotal,
            'received_total' => $result->receivedTotal,
            'invoiced_total' => $result->invoicedTotal,
            'variance_amount' => $result->varianceAmount,
            'variance_percent' => $result->variancePercent,
            'gr_ir_amount' => $result->grIrAmount,
            'price_variance_amount' => $result->priceVarianceAmount,
            'line_count' => count($result->lines),
            'discrepancy_count' => $result->discrepancyCount,
            'is_current' => true,
            'reasons' => $result->reasons,
            'tolerance_snapshot' => $result->toleranceSnapshot,
            'evaluated_at' => now(),
            'evaluated_by' => $actorId,
            'blocked_at' => $result->status->isBlocking() ? now() : null,
            'released_at' => $result->status->isBlocking() ? null : now(),
        ]);

        foreach ($result->lines as $line) {
            $run->lines()->create(['tenant_id' => $tenantId] + $line->toAttributes());
        }

        $invoice->forceFill(['purchase_order_id' => $order !== null ? (string) $order->id : $invoice->purchase_order_id])->save();

        if ($result->status->isBlocking()) {
            $invoice->blockForMatch($result->status->value);
        } else {
            $invoice->releaseMatchBlock($result->status->value);
        }

        $this->auditLedger->record(
            action: 'INVOICE_MATCH_EVALUATED',
            model: $run,
            payloadBefore: null,
            payloadAfter: ['status' => $result->status->value, 'type' => $result->type->value, 'variance_amount' => $result->varianceAmount, 'reasons' => $result->reasons],
        );

        $result->status->isBlocking() ? $this->announceBlocked($run, $invoice) : $this->announceCleared($run);

        return $run->load('lines');
    }

    private function announceBlocked(InvoiceMatchingRun $run, PassiveInvoice $invoice): void
    {
        $this->dispatcher->publishAndDispatch(new InvoiceMatchBlockedEvent(
            runId: (string) $run->id,
            passiveInvoiceId: (string) $run->passive_invoice_id,
            purchaseOrderId: $run->purchase_order_id,
            invoiceNumber: (string) $invoice->invoice_number,
            varianceAmount: (string) $run->variance_amount,
            currency: (string) $run->currency,
            reasons: $run->reasons ?? [],
            tenantId: (string) $run->tenant_id,
            companyId: (string) ($run->company_id ?? $run->tenant_id),
        ));
    }

    private function announceCleared(InvoiceMatchingRun $run): void
    {
        $this->dispatcher->publishAndDispatch(new InvoiceMatchClearedEvent(
            runId: (string) $run->id,
            passiveInvoiceId: (string) $run->passive_invoice_id,
            purchaseOrderId: $run->purchase_order_id,
            status: $run->status->value,
            grIrAmount: (string) $run->gr_ir_amount,
            tenantId: (string) $run->tenant_id,
            companyId: (string) ($run->company_id ?? $run->tenant_id),
        ));
    }
}
