<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\PurchaseRequisition\Services;

use App\Application\Procurement\Rfq\Commands\CreateRfqCommand;
use App\Application\Procurement\Rfq\Handlers\CreateRfqHandler;
use App\Domain\Procurement\PurchaseRequisition\Contracts\ProcurementConversionGatewayInterface;
use Carbon\CarbonImmutable;
use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Support\Decimal;

/**
 * Infrastructure adapter bridging Mod 044 Purchase Requisitions to Mod 045 (RFQ) and Mod 046 (PO) creation.
 */
final readonly class Mod045Mod046ConversionAdapter implements ProcurementConversionGatewayInterface
{
    public function __construct(
        private CreateRfqHandler $createRfqHandler,
        private ProcurementServiceInterface $procurementService,
    ) {}

    public function convertToRfq(
        string $tenantId,
        ?string $actorUserId,
        array $preparedLines,
        array $options,
    ): array {
        $supplierIds = array_values(array_filter(array_map('strval', (array) ($options['supplier_ids'] ?? []))));

        if ($supplierIds === []) {
            $supplierIds = Supplier::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->where('is_locked', false)
                ->orderBy('company_name')
                ->limit(1)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();
        }

        if ($supplierIds === []) {
            throw new ProcurementRuleViolation('At least one active supplier is required to create a draft RFQ.', 'supplier_ids');
        }

        $rfqLines = [];
        $prNumbers = [];
        $prIds = [];
        $costCenterId = null;

        foreach ($preparedLines as $line) {
            $prNumbers[] = $line['requisition_number'];
            $prIds[] = $line['requisition_id'];
            $costCenterId ??= $line['cost_center_id'];

            $rfqLines[] = [
                'source_requisition_line_id' => $line['requisition_line_id'],
                'internal_sku' => $line['internal_sku'],
                'custom_item_code' => $line['internal_sku'],
                'description' => $line['description'],
                'technical_specifications' => "Source PR: {$line['requisition_number']}",
                'quantity' => $line['quantity'],
                'uom' => $line['unit_of_measure'],
                'target_unit_price' => Decimal::cmp($line['estimated_unit_price'], '0', 4) > 0
                    ? $line['estimated_unit_price']
                    : null,
                'requested_delivery_date' => $line['requested_delivery_date'],
            ];
        }

        $uniquePrIds = array_values(array_unique($prIds));
        $uniquePrNumbers = array_values(array_unique($prNumbers));
        $defaultTitle = 'RFQ from '.implode(', ', $uniquePrNumbers);

        $submissionDeadline = ! empty($options['submission_deadline'])
            ? (string) $options['submission_deadline']
            : CarbonImmutable::now('UTC')->addDays(14)->toDateString();

        $rfq = $this->createRfqHandler->handle(new CreateRfqCommand(
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            title: ! empty($options['title']) ? (string) $options['title'] : $defaultTitle,
            description: $options['description'] ?? null,
            currencyCode: $options['currency'],
            incoterms: null,
            submissionDeadline: $submissionDeadline,
            expectedDeliveryDate: $options['expected_delivery_date'] ?? null,
            evaluationWeights: null,
            sealedBidding: (bool) ($options['sealed_bidding'] ?? false),
            purchaseRequisitionId: count($uniquePrIds) === 1 ? $uniquePrIds[0] : null,
            costCenterId: $costCenterId,
            notes: $options['notes'] ?? null,
            supplierIds: $supplierIds,
            lines: $rfqLines,
        ));

        $targetLineIds = $rfq->lines
            ->sortBy('line_number')
            ->values()
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return [
            'document' => $rfq,
            'document_number' => (string) $rfq->rfq_number,
            'target_line_ids' => $targetLineIds,
        ];
    }

    public function convertToPurchaseOrder(
        string $tenantId,
        ?string $actorUserId,
        string $supplierId,
        array $preparedLines,
        array $options,
    ): array {
        $poLines = [];
        $deliveryDate = $options['expected_delivery_date'] ?? null;

        foreach ($preparedLines as $line) {
            if ($deliveryDate === null && $line['requested_delivery_date'] !== null) {
                $deliveryDate = $line['requested_delivery_date'];
            }

            $catalogId = null;
            if ($line['supplier_catalog_id'] !== null && $line['supplier_catalog_id'] !== '') {
                $belongsToSupplier = SupplierCatalog::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($line['supplier_catalog_id'])
                    ->where('supplier_id', $supplierId)
                    ->exists();

                if ($belongsToSupplier) {
                    $catalogId = $line['supplier_catalog_id'];
                }
            }

            $poLines[] = [
                'description' => $line['description'],
                'quantity_ordered' => $line['quantity'],
                'unit_price' => $line['estimated_unit_price'],
                'uom' => $line['unit_of_measure'],
                'supplier_catalog_id' => $catalogId,
                'expected_delivery_date' => $line['requested_delivery_date'] ?? $deliveryDate,
            ];
        }

        $effectiveDeliveryDate = $deliveryDate ?? CarbonImmutable::now('UTC')->addDays(14)->toDateString();

        $order = $this->procurementService->createPurchaseOrder(new CreatePurchaseOrderDTO(
            supplierId: $supplierId,
            orderDate: CarbonImmutable::now('UTC')->toDateString(),
            expectedDeliveryDate: $effectiveDeliveryDate,
            lines: $poLines,
            requisitionId: $options['single_requisition_id'] ?? null,
            projectId: $options['project_id'] ?? null,
            costCenterId: $options['cost_center_id'] ?? null,
            departmentId: $options['department_id'] ?? null,
            currency: $options['currency'],
            notes: $options['notes'] ?? null,
            tenantId: $tenantId,
            creatorUserId: $actorUserId,
        ));

        $targetLineIds = $order->lines
            ->sortBy('line_number')
            ->values()
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return [
            'document' => $order,
            'document_number' => (string) ($order->po_number ?? $order->order_number),
            'target_line_ids' => $targetLineIds,
        ];
    }
}
