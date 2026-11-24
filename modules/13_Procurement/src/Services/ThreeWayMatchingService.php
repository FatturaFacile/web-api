<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Models\PassiveInvoice;
use App\Models\User;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Contracts\ThreeWayMatchingServiceInterface;
use Modules\Procurement\DTOs\ExecuteThreeWayMatchDTO;
use Modules\Procurement\Events\ThreeWayMatchDiscrepancyDetectedEvent;
use Modules\Procurement\Events\ThreeWayMatchPassedEvent;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReceiptLine;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\ThreeWayMatchLog;
use Modules\Procurement\Support\Decimal;

/**
 * Three-way match of a supplier invoice against its purchase order and the goods received. The net amount of the
 * invoice is compared with the value still to be invoiced of the goods received (accepted less returned, at the order
 * price) and with the ordered value. Within the tolerance (percentage of the received value or absolute amount,
 * whichever is larger) the invoice matches; above it the invoice is blocked for payment until the variance is approved.
 * An invoice below the received value (partial invoice) matches.
 */
final class ThreeWayMatchingService implements ThreeWayMatchingServiceInterface
{
    /** Match statuses that leave the invoice payable. */
    public const array ClearingStatuses = ['matched', 'override_approved'];

    public function __construct(
        private readonly ProcurementAuditLedgerService $auditLedger,
        private readonly EventDispatcher $dispatcher,
        private readonly ProcurementSettingsService $settings,
    ) {}

    /**
     * Runs the match and records it.
     *
     * @throws ProcurementRuleViolation when the receipt, the invoice or the supplier do not belong to the order
     */
    public function executeMatch(ExecuteThreeWayMatchDTO $dto): ThreeWayMatchLog
    {
        return DB::transaction(function () use ($dto): ThreeWayMatchLog {
            $po = PurchaseOrder::query()->whereKey($dto->purchaseOrderId)->lockForUpdate()->firstOrFail();
            $receipt = PurchaseReceipt::query()->whereKey($dto->purchaseReceiptId)->where('purchase_order_id', $po->id)->first();

            if ($receipt === null) {
                throw new ProcurementRuleViolation('The goods receipt does not belong to the purchase order.', 'purchase_receipt_id');
            }

            $invoice = PassiveInvoice::query()->whereKey($dto->passiveInvoiceId)->lockForUpdate()->first();

            if ($invoice === null) {
                throw new ProcurementRuleViolation('The supplier invoice does not exist in the active company.', 'passive_invoice_id');
            }

            if ((string) $invoice->supplier_id !== (string) $po->supplier_id) {
                throw new ProcurementRuleViolation('The invoice was issued by another supplier than the one of the order.', 'passive_invoice_id');
            }

            $tenantId = (string) $po->tenant_id;
            $tolerance = $this->settings->priceTolerance($tenantId, Supplier::query()->find($po->supplier_id));
            $orderedValue = Decimal::of($po->net_amount ?? $po->total_net, 2);
            $receivedValue = $this->receivedValue($po);
            $alreadyInvoiced = $this->alreadyInvoiced($po, (string) $invoice->id);
            $toInvoice = Decimal::sub($receivedValue, $alreadyInvoiced, 2);
            $invoiceNet = Decimal::isPositive((string) ($invoice->total_net ?? '0'))
                ? Decimal::of($invoice->total_net, 2)
                : Decimal::of($dto->invoiceTotalAmount, 2);

            $variance = Decimal::sub($invoiceNet, $toInvoice, 2);
            $allowed = Decimal::max(Decimal::mul($toInvoice, bcdiv($tolerance['percent'], '100', 8), 2), $tolerance['amount'], 2);
            $overOrdered = Decimal::cmp(Decimal::add($alreadyInvoiced, $invoiceNet, 2), Decimal::add(Decimal::plusPercent($orderedValue, $tolerance['percent'], 2), $tolerance['amount'], 2), 2) > 0;

            $status = match (true) {
                $overOrdered => 'quantity_variance',
                Decimal::cmp($variance, $allowed, 2) > 0 => 'price_variance',
                default => 'matched',
            };

            $discrepancies = $status === 'matched' ? [] : [[
                'issue' => $status === 'quantity_variance' ? 'Invoiced beyond the ordered value' : 'Invoiced beyond the value of the goods received',
                'invoice_net_amount' => $invoiceNet,
                'value_to_invoice' => $toInvoice,
                'ordered_value' => $orderedValue,
                'variance_amount' => $variance,
                'allowed_variance' => $allowed,
            ]];

            $log = ThreeWayMatchLog::create([
                'tenant_id' => $tenantId,
                'company_id' => $po->company_id,
                'purchase_order_id' => $po->id,
                'purchase_receipt_id' => $receipt->id,
                'passive_invoice_id' => $invoice->id,
                'match_status' => $status,
                'po_total_amount' => $orderedValue,
                'grn_total_amount' => $receivedValue,
                'invoice_total_amount' => $invoiceNet,
                'variance_amount' => $variance,
                'variance_percentage' => Decimal::max('-999.99', Decimal::min(Decimal::percentOf($variance, $toInvoice, 2), '999.99', 2), 2),
                'tolerance_percent' => $tolerance['percent'],
                'tolerance_amount' => $tolerance['amount'],
                'discrepancy_details' => $discrepancies,
                'evaluated_at' => now(),
                'approved_by' => $status === 'matched' ? $dto->approvedByUserId : null,
            ]);

            $invoice->forceFill(['purchase_order_id' => $po->id])->save();

            if ($status === 'matched') {
                $invoice->releaseMatchBlock('matched');
            } elseif ($this->settings->forTenant($tenantId)->block_invoice_on_mismatch) {
                $invoice->blockForMatch($status);
            } else {
                $invoice->update(['match_status' => $status]);
            }

            $this->auditLedger->record(action: 'THREE_WAY_MATCH_EVALUATED', model: $log, payloadBefore: null, payloadAfter: ['match_status' => $status, 'variance_amount' => $variance]);
            $this->announce($log, $po, $status === 'matched');

            return $log;
        });
    }

    /**
     * Approves the variance of a failed match: the invoice is released for payment.
     *
     * @throws ProcurementRuleViolation when the match has no variance to approve
     */
    public function approveVariance(ThreeWayMatchLog $log, ?User $approver, string $reason): ThreeWayMatchLog
    {
        return DB::transaction(function () use ($log, $approver, $reason): ThreeWayMatchLog {
            $locked = ThreeWayMatchLog::query()->whereKey($log->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->match_status, ['price_variance', 'quantity_variance', 'escalated_to_workflow'], true)) {
                throw new ProcurementRuleViolation("A match in status {$locked->match_status} has no variance to approve.", 'match_status');
            }

            $locked->update(['match_status' => 'override_approved', 'approved_by' => $approver?->id, 'override_at' => now(), 'override_reason' => $reason]);
            PassiveInvoice::query()->whereKey($locked->passive_invoice_id)->lockForUpdate()->first()?->releaseMatchBlock('override_approved');

            $this->auditLedger->record(action: 'THREE_WAY_MATCH_VARIANCE_APPROVED', model: $locked, payloadBefore: ['match_status' => $log->match_status], payloadAfter: ['match_status' => 'override_approved', 'reason' => $reason]);
            $this->announce($locked, PurchaseOrder::query()->findOrFail($locked->purchase_order_id), true);

            return $locked;
        });
    }

    /**
     * Value of the goods received on the order and not returned for credit, at the order price.
     */
    private function receivedValue(PurchaseOrder $po): string
    {
        $value = '0.00';
        $lines = PurchaseReceiptLine::query()
            ->with('orderLine')
            ->whereHas('receipt', static fn ($receipt) => $receipt->where('purchase_order_id', $po->id))
            ->get();

        foreach ($lines as $line) {
            $net = Decimal::sub((string) $line->quantity_accepted, (string) $line->quantity_returned, 4);
            $value = Decimal::add($value, Decimal::mul($net, (string) ($line->orderLine->unit_price ?? '0'), 2), 2);
        }

        return $value;
    }

    /**
     * Net amount of the other invoices of the order already matched or approved.
     */
    private function alreadyInvoiced(PurchaseOrder $po, string $invoiceId): string
    {
        $total = ThreeWayMatchLog::query()
            ->where('purchase_order_id', $po->id)
            ->where('passive_invoice_id', '!=', $invoiceId)
            ->whereIn('match_status', self::ClearingStatuses)
            ->get()
            ->unique('passive_invoice_id')
            ->reduce(static fn (string $sum, ThreeWayMatchLog $log): string => Decimal::add($sum, (string) $log->invoice_total_amount, 2), '0.00');

        return Decimal::of($total, 2);
    }

    private function announce(ThreeWayMatchLog $log, PurchaseOrder $po, bool $passed): void
    {
        if ($passed) {
            $this->dispatcher->publishAndDispatch(new ThreeWayMatchPassedEvent(
                matchLogId: (string) $log->id,
                purchaseOrderId: (string) $po->id,
                receiptId: (string) $log->purchase_receipt_id,
                passiveInvoiceId: (string) $log->passive_invoice_id,
                invoiceTotal: (float) $log->invoice_total_amount,
                tenantId: (string) $po->tenant_id,
                companyId: (string) ($po->company_id ?? $po->tenant_id)
            ));

            return;
        }

        $this->dispatcher->publishAndDispatch(new ThreeWayMatchDiscrepancyDetectedEvent(
            matchLogId: (string) $log->id,
            purchaseOrderId: (string) $po->id,
            receiptId: (string) $log->purchase_receipt_id,
            passiveInvoiceId: (string) $log->passive_invoice_id,
            varianceAmount: (float) $log->variance_amount,
            variancePercentage: (float) $log->variance_percentage,
            discrepancyDetails: (array) $log->discrepancy_details,
            tenantId: (string) $po->tenant_id,
            companyId: (string) ($po->company_id ?? $po->tenant_id)
        ));
    }
}
