<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Procurement\Contracts\ThreeWayMatchingServiceInterface;
use Modules\Procurement\DTOs\ExecuteThreeWayMatchDTO;
use Modules\Procurement\Events\ThreeWayMatchDiscrepancyDetectedEvent;
use Modules\Procurement\Events\ThreeWayMatchPassedEvent;
use Modules\Procurement\Models\GoodsReceiptNote;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\ThreeWayMatchLog;

final class ThreeWayMatchingService implements ThreeWayMatchingServiceInterface
{
    public function __construct(
        private readonly ProcurementAuditLedgerService $auditLedger,
        private readonly ?EventBusInterface $eventBus = null
    ) {}

    public function executeMatch(ExecuteThreeWayMatchDTO $dto): ThreeWayMatchLog
    {
        return DB::transaction(function () use ($dto): ThreeWayMatchLog {
            $po = PurchaseOrder::where('id', $dto->purchaseOrderId)->lockForUpdate()->firstOrFail();
            $receipt = PurchaseReceipt::where('id', $dto->purchaseReceiptId)->firstOrFail();

            $poTotal = (float) ($po->total_amount ?? $po->total_gross ?? 0.0);
            $grnTotal = (float) $receipt->lines()->sum(DB::raw('quantity_accepted * 1.0'));
            $invoiceTotal = $dto->invoiceTotalAmount;

            $varianceAmount = abs($invoiceTotal - $poTotal);
            $variancePct = $poTotal > 0.0 ? ($varianceAmount / $poTotal) * 100.0 : 0.0;

            // Enterprise Tolerance Rules: Price variance <= 1.0%
            $isTolerated = $variancePct <= 1.0;
            $matchStatus = $isTolerated ? 'matched' : 'price_variance';

            $discrepancies = [];
            if (! $isTolerated) {
                $discrepancies[] = [
                    'issue' => 'Price variance exceeds 1.0% contractual tolerance',
                    'variance_amount' => $varianceAmount,
                    'variance_percentage' => round($variancePct, 2),
                ];
            }

            $log = ThreeWayMatchLog::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $po->tenant_id,
                'company_id' => $po->company_id,
                'purchase_order_id' => $po->id,
                'purchase_receipt_id' => $receipt->id,
                'passive_invoice_id' => $dto->passiveInvoiceId,
                'match_status' => $matchStatus,
                'po_total_amount' => $poTotal,
                'grn_total_amount' => $grnTotal,
                'invoice_total_amount' => $invoiceTotal,
                'variance_amount' => $varianceAmount,
                'variance_percentage' => round($variancePct, 2),
                'discrepancy_details' => $discrepancies,
                'evaluated_at' => now(),
                'approved_by' => $dto->approvedByUserId,
            ]);

            $this->auditLedger->record(
                action: 'THREE_WAY_MATCH_EVALUATED',
                model: $log,
                payloadBefore: null,
                payloadAfter: [
                    'match_status' => $matchStatus,
                    'variance_amount' => $varianceAmount,
                    'variance_percentage' => $variancePct,
                ]
            );

            if ($matchStatus === 'matched') {
                $this->dispatchDomainEvent(new ThreeWayMatchPassedEvent(
                    matchLogId: $log->id,
                    purchaseOrderId: $po->id,
                    receiptId: $receipt->id,
                    passiveInvoiceId: $dto->passiveInvoiceId,
                    invoiceTotal: $invoiceTotal,
                    tenantId: $po->tenant_id,
                    companyId: (string) ($po->company_id ?? $po->tenant_id)
                ));
            } else {
                $this->dispatchDomainEvent(new ThreeWayMatchDiscrepancyDetectedEvent(
                    matchLogId: $log->id,
                    purchaseOrderId: $po->id,
                    receiptId: $receipt->id,
                    passiveInvoiceId: $dto->passiveInvoiceId,
                    varianceAmount: $varianceAmount,
                    variancePercentage: round($variancePct, 2),
                    discrepancyDetails: $discrepancies,
                    tenantId: $po->tenant_id,
                    companyId: (string) ($po->company_id ?? $po->tenant_id)
                ));
            }

            return $log;
        });
    }

    private function dispatchDomainEvent(object $event): void
    {
        if ($this->eventBus !== null && $event instanceof DomainEventInterface) {
            $this->eventBus->publish($event);
        } else {
            event($event);
        }
    }
}
