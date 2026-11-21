<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Support;

use App\Domain\Procurement\PurchaseRequisition\Contracts\CatalogItemResolverInterface;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionLineConversionStatus;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionLineItemType;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionLine;
use App\Domain\Procurement\PurchaseRequisition\Services\RequisitionTotalsDomainService;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Support\Decimal;

/**
 * Shared application collaborator that resolves catalog items, computes line totals, and writes requisition lines.
 */
final readonly class RequisitionLineWriteSupport
{
    public function __construct(
        private CatalogItemResolverInterface $catalogResolver,
        private RequisitionTotalsDomainService $totalsService,
    ) {}

    /**
     * Resolves and persists line items for a requisition, returning the aggregated total estimated amount.
     *
     * @param  list<array{item_type?: string, catalog_item_id?: ?string, description?: string, quantity: string|int|float, unit_of_measure?: string, estimated_unit_price?: string|int|float|null, cost_center_id?: ?string, project_id?: ?string}>  $linesPayload
     */
    public function replaceLines(PurchaseRequisition $requisition, array $linesPayload): string
    {
        $tenantId = (string) $requisition->tenant_id;
        $preparedSummaryLines = [];
        $lineAttributes = [];

        foreach ($linesPayload as $index => $line) {
            $lineNumber = $index + 1;
            $catalogItemId = isset($line['catalog_item_id']) && trim((string) $line['catalog_item_id']) !== ''
                ? trim((string) $line['catalog_item_id'])
                : null;

            $itemTypeValue = isset($line['item_type']) && trim((string) $line['item_type']) !== ''
                ? trim((string) $line['item_type'])
                : ($catalogItemId !== null ? RequisitionLineItemType::CatalogItem->value : RequisitionLineItemType::FreeText->value);

            $itemType = RequisitionLineItemType::tryFrom($itemTypeValue)
                ?? throw new ProcurementRuleViolation(
                    __('purchase_requisition.validation.item_type_invalid'),
                    "lines.{$index}.item_type"
                );

            $supplierCatalogId = null;
            $suggestedSupplierId = null;
            $resolvedDescription = isset($line['description']) ? trim((string) $line['description']) : '';
            $resolvedUom = isset($line['unit_of_measure']) ? trim((string) $line['unit_of_measure']) : '';
            $resolvedUnitPrice = array_key_exists('estimated_unit_price', $line) && $line['estimated_unit_price'] !== null
                ? Decimal::of($line['estimated_unit_price'], 4)
                : null;

            if ($itemType === RequisitionLineItemType::CatalogItem) {
                if ($catalogItemId === null) {
                    throw new ProcurementRuleViolation(
                        __('purchase_requisition.validation.catalog_item_required'),
                        "lines.{$index}.catalog_item_id"
                    );
                }

                $catalogData = $this->catalogResolver->resolve($tenantId, $catalogItemId);
                if ($catalogData === null) {
                    throw new ProcurementRuleViolation(
                        __('purchase_requisition.validation.catalog_item_not_found'),
                        "lines.{$index}.catalog_item_id"
                    );
                }

                $supplierCatalogId = $catalogData['supplier_catalog_id'];
                $suggestedSupplierId = $catalogData['suggested_supplier_id'];

                if ($resolvedDescription === '') {
                    $resolvedDescription = $catalogData['description'];
                }

                if ($resolvedUom === '') {
                    $resolvedUom = $catalogData['unit_of_measure'];
                }

                if ($resolvedUnitPrice === null) {
                    $resolvedUnitPrice = Decimal::of($catalogData['estimated_unit_price'], 4);
                }
            }

            if ($resolvedDescription === '') {
                throw new ProcurementRuleViolation(
                    __('purchase_requisition.validation.description_required'),
                    "lines.{$index}.description"
                );
            }

            if ($resolvedUom === '') {
                $resolvedUom = 'PCS';
            }

            $quantity = Decimal::of($line['quantity'], 4);
            $unitPrice = $resolvedUnitPrice ?? Decimal::of('0', 4);
            $lineTotal = $this->totalsService->computeLineTotal($quantity, $unitPrice);

            $lineCostCenterId = isset($line['cost_center_id']) && trim((string) $line['cost_center_id']) !== ''
                ? trim((string) $line['cost_center_id'])
                : null;
            $lineProjectId = isset($line['project_id']) && trim((string) $line['project_id']) !== ''
                ? trim((string) $line['project_id'])
                : null;

            $preparedSummaryLines[] = [
                'quantity' => $quantity,
                'estimated_unit_price' => $unitPrice,
                'cost_center_id' => $lineCostCenterId,
            ];

            $lineAttributes[] = [
                'tenant_id' => $tenantId,
                'purchase_requisition_id' => $requisition->id,
                'line_number' => $lineNumber,
                'item_type' => $itemType->value,
                'catalog_item_id' => $catalogItemId,
                'supplier_catalog_id' => $supplierCatalogId,
                'suggested_supplier_id' => $suggestedSupplierId,
                'description' => $resolvedDescription,
                'quantity' => $quantity,
                'quantity_requested' => $quantity,
                'unit_of_measure' => $resolvedUom,
                'uom' => $resolvedUom,
                'estimated_unit_price' => $unitPrice,
                'estimated_line_total' => $lineTotal,
                'estimated_total_price' => $lineTotal,
                'cost_center_id' => $lineCostCenterId,
                'project_id' => $lineProjectId,
                'converted_quantity' => '0.0000',
                'conversion_status' => RequisitionLineConversionStatus::Unconverted->value,
                'quantity_quoted' => '0.0000',
                'quantity_ordered' => '0.0000',
                'sourcing_status' => 'pending',
            ];
        }

        // Soft-delete existing lines first so updates leave clean audit history.
        $requisition->lines()->get()->each(static function (PurchaseRequisitionLine $existing): void {
            $existing->delete();
        });

        foreach ($lineAttributes as $attrs) {
            PurchaseRequisitionLine::query()->create($attrs);
        }

        $summary = $this->totalsService->summarizeDraftPayload(
            $requisition->cost_center_id !== null ? (string) $requisition->cost_center_id : null,
            $preparedSummaryLines
        );

        return $summary['total_estimated_amount'];
    }
}
