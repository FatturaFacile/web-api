<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\Rfq\Services;

use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionStatus;
use App\Domain\Procurement\Rfq\Contracts\RequisitionSourcingAdapterInterface;
use App\Domain\Procurement\Rfq\Enums\RfqStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqLine;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Enums\PurchaseRequisitionStatus;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\PurchaseRequisitionLine;
use Modules\Procurement\Support\Decimal;

/**
 * Infrastructure adapter linking Mod 045 RFQs with Mod 044 Purchase Requisitions.
 */
final class Mod044RequisitionAdapter implements RequisitionSourcingAdapterInterface
{
    /**
     * @param  list<string>  $requisitionIds
     * @param  list<string>|null  $lineIds
     * @return list<array{id: string, purchase_requisition_id: string, requisition_number: string, currency: string, cost_center_id: ?string, supplier_catalog_id: ?string, internal_sku: ?string, description: string, quantity_requested: string, quantity_quoted: string, remaining_quantity: string, uom: string, estimated_unit_price: string, required_by_date: ?string}>
     */
    public function getQuotableLines(string $tenantId, array $requisitionIds, ?array $lineIds = null): array
    {
        $requisitions = PurchaseRequisition::query()
            ->with('lines.catalog')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $requisitionIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($requisitionIds as $reqId) {
            $requisition = $requisitions->get($reqId);

            if ($requisition === null) {
                throw new ProcurementRuleViolation("Purchase requisition {$reqId} was not found in this tenant.", 'purchase_requisition_ids');
            }

            if (! in_array((string) $requisition->status, [
                PurchaseRequisitionStatus::Approved->value,
                PurchaseRequisitionStatus::Sourcing->value,
                RequisitionStatus::PartiallyConverted->value,
            ], true)) {
                throw new ProcurementRuleViolation("Purchase requisition {$requisition->requisition_number} must be approved before creating an RFQ.", 'purchase_requisition_ids');
            }
        }

        $lineFilter = $lineIds !== null ? array_flip($lineIds) : null;
        $result = [];

        foreach ($requisitionIds as $reqId) {
            /** @var PurchaseRequisition $requisition */
            $requisition = $requisitions->get($reqId);

            foreach ($requisition->lines as $line) {
                $lineId = (string) $line->id;

                if ($lineFilter !== null && ! isset($lineFilter[$lineId])) {
                    continue;
                }

                $requested = Decimal::of((string) $line->quantity_requested, 4);
                $quoted = Decimal::of((string) ($line->quantity_quoted ?? '0'), 4);
                $ordered = Decimal::of((string) ($line->quantity_ordered ?? '0'), 4);
                $converted = Decimal::of((string) ($line->getAttribute('converted_quantity') ?? '0'), 4);
                $consumed = Decimal::max(Decimal::max($quoted, $ordered, 4), $converted, 4);
                $remaining = Decimal::sub($requested, $consumed, 4);

                if (Decimal::cmp($remaining, '0', 4) <= 0) {
                    if ($lineFilter !== null) {
                        throw new ProcurementRuleViolation("Requisition line {$line->description} has no remaining unquoted quantity.", 'line_ids');
                    }

                    continue;
                }

                $result[] = [
                    'id' => $lineId,
                    'purchase_requisition_id' => (string) $requisition->id,
                    'requisition_number' => (string) $requisition->requisition_number,
                    'currency' => strtoupper((string) $requisition->currency),
                    'cost_center_id' => $requisition->cost_center_id !== null ? (string) $requisition->cost_center_id : null,
                    'supplier_catalog_id' => $line->supplier_catalog_id !== null ? (string) $line->supplier_catalog_id : null,
                    'internal_sku' => $line->catalog?->internal_sku,
                    'description' => (string) $line->description,
                    'quantity_requested' => $requested,
                    'quantity_quoted' => $quoted,
                    'remaining_quantity' => $remaining,
                    'uom' => (string) $line->uom,
                    'estimated_unit_price' => Decimal::of((string) ($line->estimated_unit_price ?? '0'), 4),
                    'required_by_date' => $requisition->required_by_date?->toDateString(),
                ];
            }
        }

        if ($lineFilter !== null) {
            $foundIds = array_column($result, 'id');
            foreach (array_keys($lineFilter) as $expectedId) {
                if (! in_array($expectedId, $foundIds, true)) {
                    throw new ProcurementRuleViolation("Requisition line {$expectedId} does not belong to the selected approved requisitions.", 'line_ids');
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $lineQuantities
     */
    public function lockLinesForRfq(string $tenantId, array $lineQuantities): void
    {
        if ($lineQuantities === []) {
            return;
        }

        $lines = PurchaseRequisitionLine::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_keys($lineQuantities))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $affectedReqIds = [];

        foreach ($lineQuantities as $lineId => $qtyToLock) {
            $line = $lines->get($lineId);

            if ($line === null) {
                throw new ProcurementRuleViolation("Source requisition line {$lineId} was not found.", 'lines');
            }

            $qty = Decimal::of($qtyToLock, 4);
            $requested = Decimal::of((string) $line->quantity_requested, 4);
            $alreadyQuoted = Decimal::of((string) ($line->quantity_quoted ?? '0'), 4);
            $newQuoted = Decimal::add($alreadyQuoted, $qty, 4);

            if (Decimal::cmp($newQuoted, $requested, 4) > 0) {
                throw new ProcurementRuleViolation("Quoted quantity exceeds remaining unquoted quantity for requisition line '{$line->description}'.", 'lines');
            }

            $line->update([
                'quantity_quoted' => $newQuoted,
                'sourcing_status' => 'in_quotation',
            ]);

            $affectedReqIds[] = (string) $line->purchase_requisition_id;
        }

        PurchaseRequisition::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_values(array_unique($affectedReqIds)))
            ->where('status', PurchaseRequisitionStatus::Approved->value)
            ->update(['status' => PurchaseRequisitionStatus::Sourcing->value]);
    }

    /**
     * @param  array<string, string>  $lineQuantities
     */
    public function releaseLinesFromRfq(string $tenantId, array $lineQuantities, ?string $excludingRfqId = null): void
    {
        if ($lineQuantities === []) {
            return;
        }

        $lines = PurchaseRequisitionLine::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_keys($lineQuantities))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $affectedReqIds = [];

        foreach ($lineQuantities as $lineId => $qtyToRelease) {
            $line = $lines->get($lineId);

            if ($line === null) {
                continue;
            }

            $currentQuoted = Decimal::of((string) ($line->quantity_quoted ?? '0'), 4);
            $release = Decimal::of($qtyToRelease, 4);
            $remainingQuoted = Decimal::sub($currentQuoted, $release, 4);

            if (Decimal::cmp($remainingQuoted, '0', 4) < 0) {
                $remainingQuoted = '0.0000';
            }

            $ordered = Decimal::of((string) ($line->quantity_ordered ?? '0'), 4);
            $sourcingStatus = match (true) {
                Decimal::cmp($ordered, (string) $line->quantity_requested, 4) >= 0 => 'ordered',
                Decimal::cmp($remainingQuoted, '0', 4) > 0 => 'in_quotation',
                default => 'open',
            };

            $line->update([
                'quantity_quoted' => $remainingQuoted,
                'sourcing_status' => $sourcingStatus,
            ]);

            $affectedReqIds[] = (string) $line->purchase_requisition_id;
        }

        $this->reconcileRequisitionHeaders($tenantId, array_values(array_unique($affectedReqIds)), $excludingRfqId);
    }

    public function markRfqLinesOrdered(RequestForQuotation $rfq): void
    {
        $tenantId = (string) $rfq->tenant_id;
        $rfqLines = $rfq->lines()->get();
        $prLineIds = $rfqLines
            ->pluck('purchase_requisition_line_id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        $affectedReqIds = [];

        if ($rfq->purchase_requisition_id !== null) {
            $affectedReqIds[] = (string) $rfq->purchase_requisition_id;
        }

        if ($prLineIds !== []) {
            $prLines = PurchaseRequisitionLine::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $prLineIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($rfqLines as $rfqLine) {
                /** @var RfqLine $rfqLine */
                if ($rfqLine->purchase_requisition_line_id === null || $rfqLine->awarded_bid_line_id === null) {
                    continue;
                }

                $prLine = $prLines->get((string) $rfqLine->purchase_requisition_line_id);
                if ($prLine === null) {
                    continue;
                }

                $newOrdered = Decimal::add(
                    Decimal::of((string) ($prLine->quantity_ordered ?? '0'), 4),
                    Decimal::of((string) $rfqLine->quantity, 4),
                    4
                );
                $requested = Decimal::of((string) $prLine->quantity_requested, 4);

                if (Decimal::cmp($newOrdered, $requested, 4) > 0) {
                    $newOrdered = $requested;
                }

                $prLine->update([
                    'quantity_ordered' => $newOrdered,
                    'sourcing_status' => 'ordered',
                ]);

                $affectedReqIds[] = (string) $prLine->purchase_requisition_id;
            }
        }

        $affectedReqIds = array_values(array_unique($affectedReqIds));

        if ($affectedReqIds === []) {
            return;
        }

        // If the RFQ came from a single legacy requisition header without line links, mark it ordered directly.
        if ($rfq->purchase_requisition_id !== null && $prLineIds === []) {
            PurchaseRequisition::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($rfq->purchase_requisition_id)
                ->update(['status' => PurchaseRequisitionStatus::Ordered->value]);

            PurchaseRequisitionLine::query()
                ->where('tenant_id', $tenantId)
                ->where('purchase_requisition_id', $rfq->purchase_requisition_id)
                ->update([
                    'sourcing_status' => 'ordered',
                    'quantity_ordered' => DB::raw('quantity_requested'),
                ]);

            return;
        }

        $requisitions = PurchaseRequisition::query()
            ->with('lines')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $affectedReqIds)
            ->lockForUpdate()
            ->get();

        foreach ($requisitions as $requisition) {
            $allOrdered = $requisition->lines->every(
                static fn (PurchaseRequisitionLine $line): bool => $line->sourcing_status === 'ordered'
            );

            if ($allOrdered) {
                $requisition->update(['status' => PurchaseRequisitionStatus::Ordered->value]);
            }
        }
    }

    /**
     * @param  list<string>  $requisitionIds
     */
    private function reconcileRequisitionHeaders(string $tenantId, array $requisitionIds, ?string $excludingRfqId): void
    {
        if ($requisitionIds === []) {
            return;
        }

        $requisitions = PurchaseRequisition::query()
            ->with('lines')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $requisitionIds)
            ->lockForUpdate()
            ->get();

        foreach ($requisitions as $requisition) {
            if ((string) $requisition->status !== PurchaseRequisitionStatus::Sourcing->value) {
                continue;
            }

            $anyActiveLine = $requisition->lines->contains(
                static fn (PurchaseRequisitionLine $line): bool => in_array((string) $line->sourcing_status, ['in_quotation', 'ordered'], true)
            );

            $otherActiveRfq = RfqLine::query()
                ->whereIn('purchase_requisition_line_id', $requisition->lines->pluck('id')->all())
                ->when($excludingRfqId !== null, static fn ($q) => $q->where('rfq_id', '!=', $excludingRfqId))
                ->whereHas('rfq', static fn ($q) => $q->whereNotIn('status', [RfqStatus::Cancelled->value, RfqStatus::Expired->value]))
                ->exists();

            if (! $anyActiveLine && ! $otherActiveRfq) {
                $requisition->update(['status' => PurchaseRequisitionStatus::Approved->value]);
            }
        }
    }
}
