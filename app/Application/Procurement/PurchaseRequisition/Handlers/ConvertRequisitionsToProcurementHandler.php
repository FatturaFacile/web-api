<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Handlers;

use App\Application\Procurement\PurchaseRequisition\Commands\ConvertRequisitionsToProcurementCommand;
use App\Domain\Procurement\PurchaseRequisition\Contracts\ProcurementConversionGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Enums\DocumentLinkTargetType;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionLineConversionStatus;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionDocumentLink;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionLine;
use App\Domain\Procurement\PurchaseRequisition\Services\RequisitionTotalsDomainService;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\ConversionResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Services\ProcurementAuditLedgerService;
use Modules\Procurement\Support\Decimal;

/**
 * Converts one or more approved/partially-converted Purchase Requisition lines (full or partial quantities)
 * into a draft Mod 045 RFQ or Mod 046 PO while maintaining bidirectional document traceability.
 */
final readonly class ConvertRequisitionsToProcurementHandler
{
    public function __construct(
        private ProcurementConversionGatewayInterface $conversionGateway,
        private RequisitionTotalsDomainService $totalsService,
        private ProcurementAuditLedgerService $auditLedger,
    ) {}

    public function handle(ConvertRequisitionsToProcurementCommand $command): ConversionResult
    {
        return DB::transaction(function () use ($command): ConversionResult {
            $requestedLineIds = array_values(array_unique(array_map(
                static fn (array $item): string => (string) $item['requisition_line_id'],
                $command->items
            )));

            if ($requestedLineIds === []) {
                throw new ProcurementRuleViolation(__('purchase_requisition.validation.items_required'), 'items');
            }

            $lines = PurchaseRequisitionLine::query()
                ->where('tenant_id', $command->tenantId)
                ->whereIn('id', $requestedLineIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lines->count() !== count($requestedLineIds)) {
                throw new ProcurementRuleViolation(
                    __('purchase_requisition.errors.line_not_found', ['line_id' => implode(', ', $requestedLineIds)]),
                    'items'
                );
            }

            $requisitionIds = $lines
                ->pluck('purchase_requisition_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $requisitions = PurchaseRequisition::query()
                ->where('tenant_id', $command->tenantId)
                ->whereIn('id', $requisitionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($requisitions->count() !== count($requisitionIds)) {
                throw new ProcurementRuleViolation(
                    __('purchase_requisition.errors.line_not_found', ['line_id' => implode(', ', $requestedLineIds)]),
                    'items'
                );
            }

            $currencies = [];
            $preparedLines = [];
            $quantitiesByLineId = [];

            foreach ($command->items as $index => $item) {
                $lineId = (string) $item['requisition_line_id'];
                /** @var PurchaseRequisitionLine $line */
                $line = $lines->get($lineId);
                /** @var PurchaseRequisition $requisition */
                $requisition = $requisitions->get($line->purchase_requisition_id);

                if (! $requisition->statusEnum()->isConvertible()) {
                    throw new ProcurementRuleViolation(
                        __('purchase_requisition.errors.cannot_convert_unapproved', [
                            'number' => $requisition->requisition_number,
                            'status' => $requisition->status,
                        ]),
                        "items.{$index}.requisition_line_id"
                    );
                }

                $qty = Decimal::of($item['quantity'], 4);
                if (Decimal::cmp($qty, '0', 4) <= 0) {
                    throw new ProcurementRuleViolation(
                        __('purchase_requisition.validation.quantity_positive'),
                        "items.{$index}.quantity"
                    );
                }

                $quantitiesByLineId[$lineId] = Decimal::add($quantitiesByLineId[$lineId] ?? '0.0000', $qty, 4);
                $remaining = $line->remainingConvertibleQuantity();

                if (Decimal::cmp($quantitiesByLineId[$lineId], $remaining, 4) > 0) {
                    throw new ProcurementRuleViolation(
                        __('purchase_requisition.errors.exceeds_remaining_quantity', [
                            'requested' => $quantitiesByLineId[$lineId],
                            'remaining' => $remaining,
                            'description' => $line->description,
                        ]),
                        "items.{$index}.quantity"
                    );
                }

                $currencies[] = strtoupper((string) $requisition->currency);

                $preparedLines[] = [
                    'requisition_id' => $requisition->id,
                    'requisition_number' => $requisition->requisition_number,
                    'requisition_line_id' => $line->id,
                    'supplier_catalog_id' => $line->supplier_catalog_id !== null ? (string) $line->supplier_catalog_id : null,
                    'internal_sku' => $line->catalog_item_id !== null ? (string) $line->catalog_item_id : null,
                    'description' => $line->description,
                    'quantity' => $qty,
                    'unit_of_measure' => $line->effectiveUnitOfMeasure(),
                    'estimated_unit_price' => (string) $line->estimated_unit_price,
                    'cost_center_id' => $line->cost_center_id !== null ? (string) $line->cost_center_id : ($requisition->cost_center_id !== null ? (string) $requisition->cost_center_id : null),
                    'project_id' => $line->project_id !== null ? (string) $line->project_id : ($requisition->project_id !== null ? (string) $requisition->project_id : null),
                    'requested_delivery_date' => $requisition->requested_delivery_date?->toDateString(),
                ];
            }

            $uniqueCurrencies = array_values(array_unique($currencies));
            if (count($uniqueCurrencies) > 1) {
                throw new ProcurementRuleViolation(
                    __('purchase_requisition.errors.mixed_currency_conversion'),
                    'items'
                );
            }

            $currency = $uniqueCurrencies[0] ?? 'EUR';
            $targetType = strtolower(trim($command->targetType));

            if ($targetType === 'rfq') {
                $conversion = $this->conversionGateway->convertToRfq(
                    tenantId: $command->tenantId,
                    actorUserId: $command->actorUserId,
                    preparedLines: $preparedLines,
                    options: [
                        'title' => $command->title,
                        'currency' => $currency,
                        'submission_deadline' => $command->submissionDeadline,
                        'expected_delivery_date' => $command->expectedDeliveryDate,
                        'notes' => $command->notes,
                        'supplier_ids' => $command->supplierIds ?? ($command->supplierId !== null ? [$command->supplierId] : []),
                    ],
                );
                $linkTargetType = DocumentLinkTargetType::RfqMod045;
            } else {
                if ($command->supplierId === null || trim($command->supplierId) === '') {
                    throw new ProcurementRuleViolation(
                        __('purchase_requisition.validation.supplier_required_for_po'),
                        'supplier_id'
                    );
                }

                /** @var PurchaseRequisition $firstRequisition */
                $firstRequisition = $requisitions->first();
                $conversion = $this->conversionGateway->convertToPurchaseOrder(
                    tenantId: $command->tenantId,
                    actorUserId: $command->actorUserId,
                    supplierId: $command->supplierId,
                    preparedLines: $preparedLines,
                    options: [
                        'currency' => $currency,
                        'expected_delivery_date' => $command->expectedDeliveryDate,
                        'notes' => $command->notes,
                        'cost_center_id' => $preparedLines[0]['cost_center_id'] ?? null,
                        'project_id' => $preparedLines[0]['project_id'] ?? null,
                        'department_id' => $firstRequisition->department_id,
                        'single_requisition_id' => count($requisitionIds) === 1 ? $requisitionIds[0] : null,
                    ],
                );
                $linkTargetType = DocumentLinkTargetType::PoMod046;
            }

            $links = [];
            foreach ($preparedLines as $index => $prepared) {
                $targetLineId = $conversion['target_line_ids'][$index] ?? null;

                $links[] = PurchaseRequisitionDocumentLink::query()->create([
                    'tenant_id' => $command->tenantId,
                    'purchase_requisition_id' => $prepared['requisition_id'],
                    'purchase_requisition_line_id' => $prepared['requisition_line_id'],
                    'target_document_type' => $linkTargetType->value,
                    'target_document_id' => (string) $conversion['document']->getKey(),
                    'target_document_number' => $conversion['document_number'],
                    'target_document_line_id' => $targetLineId,
                    'converted_quantity' => $prepared['quantity'],
                    'created_by_user_id' => $command->actorUserId,
                ]);
            }

            // Persist cumulative converted quantities & line conversion statuses.
            foreach ($quantitiesByLineId as $lineId => $addedQty) {
                /** @var PurchaseRequisitionLine $line */
                $line = $lines->get($lineId);
                $freshLine = $line->fresh() ?? $line;
                $newConverted = Decimal::add((string) ($freshLine->converted_quantity ?? '0'), $addedQty, 4);
                $lineStatus = $this->totalsService->resolveLineConversionStatus(
                    $freshLine->effectiveQuantity(),
                    $newConverted
                );

                $lineUpdates = [
                    'converted_quantity' => $newConverted,
                    'conversion_status' => $lineStatus->value,
                ];

                if ($targetType === 'po') {
                    $newOrdered = Decimal::add((string) ($freshLine->quantity_ordered ?? '0'), $addedQty, 4);
                    $lineUpdates['quantity_ordered'] = $newOrdered;
                    $lineUpdates['sourcing_status'] = $lineStatus === RequisitionLineConversionStatus::FullyConverted
                        ? 'ordered'
                        : 'partially_ordered';
                }

                $freshLine->update($lineUpdates);
            }

            // Recompute header status strictly from cumulative line conversion state (overwriting Mod 045's 'sourcing').
            $updatedRequisitions = [];
            foreach ($requisitions as $requisition) {
                /** @var PurchaseRequisition $freshRequisition */
                $freshRequisition = $requisition->fresh(['lines']) ?? $requisition;
                $newHeaderStatus = $this->totalsService->resolveHeaderConversionStatus($freshRequisition->lines);

                $headerUpdates = [
                    'status' => $newHeaderStatus->value,
                    'is_locked' => true,
                ];

                if ($newHeaderStatus->value === 'converted') {
                    $headerUpdates['converted_at'] = CarbonImmutable::now('UTC');
                }

                $freshRequisition->update($headerUpdates);

                $this->auditLedger->record('requisition.converted', $freshRequisition, null, [
                    'target_type' => $linkTargetType->value,
                    'target_document_id' => (string) $conversion['document']->getKey(),
                    'target_document_number' => $conversion['document_number'],
                    'header_status' => $newHeaderStatus->value,
                ]);

                $updatedRequisitions[] = $freshRequisition->fresh(['lines', 'documentLinks']) ?? $freshRequisition;
            }

            return new ConversionResult(
                targetType: $targetType,
                targetDocumentId: (string) $conversion['document']->getKey(),
                targetDocumentNumber: $conversion['document_number'],
                createdDocument: $conversion['document'],
                links: $links,
                updatedRequisitions: $updatedRequisitions,
            );
        });
    }
}
