<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Commands\CreateRfqCommand;
use App\Application\Procurement\Rfq\Commands\CreateRfqFromRequisitionsCommand;
use App\Domain\Procurement\Rfq\Contracts\RequisitionSourcingAdapterInterface;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Support\Decimal;

/**
 * Consolidates open approved Mod 044 Purchase Requisition lines into a new draft RequestForQuotation.
 */
final readonly class CreateRfqFromRequisitionsHandler
{
    public function __construct(
        private RequisitionSourcingAdapterInterface $requisitions,
        private CreateRfqHandler $createHandler,
    ) {}

    public function handle(CreateRfqFromRequisitionsCommand $command): RequestForQuotation
    {
        return DB::transaction(function () use ($command): RequestForQuotation {
            $prLines = $this->requisitions->getQuotableLines(
                $command->tenantId,
                $command->purchaseRequisitionIds,
                $command->lineIds
            );

            if ($prLines === []) {
                throw new ProcurementRuleViolation('Selected requisitions have no open unquoted lines remaining.', 'purchase_requisition_ids');
            }

            $currency = $command->currencyCode !== null && trim($command->currencyCode) !== ''
                ? strtoupper(trim($command->currencyCode))
                : $prLines[0]['currency'];

            $singlePrId = count(array_unique($command->purchaseRequisitionIds)) === 1
                ? $command->purchaseRequisitionIds[0]
                : null;

            $rfqLines = [];
            $extraConsolidatedLocks = [];

            if ($command->consolidateMatchingItems) {
                /** @var array<string, array{source_requisition_line_id: string, extra_lines: array<string, string>, internal_sku: ?string, description: string, quantity: string, uom: string, target_unit_price: ?string, requested_delivery_date: ?string, specs: list<string>}> $grouped */
                $grouped = [];

                foreach ($prLines as $prLine) {
                    $key = strtolower(trim((string) ($prLine['internal_sku'] ?? ''))).'|'.strtolower(trim($prLine['description'])).'|'.strtoupper(trim($prLine['uom']));
                    $qty = $prLine['remaining_quantity'];
                    $target = Decimal::cmp($prLine['estimated_unit_price'], '0', 4) > 0 ? $prLine['estimated_unit_price'] : null;

                    if (! isset($grouped[$key])) {
                        $grouped[$key] = [
                            'source_requisition_line_id' => $prLine['id'],
                            'extra_lines' => [],
                            'internal_sku' => $prLine['internal_sku'],
                            'description' => $prLine['description'],
                            'quantity' => $qty,
                            'uom' => $prLine['uom'],
                            'target_unit_price' => $target,
                            'requested_delivery_date' => $prLine['required_by_date'],
                            'specs' => ["PR {$prLine['requisition_number']}"],
                        ];
                    } else {
                        $grouped[$key]['quantity'] = Decimal::add($grouped[$key]['quantity'], $qty, 4);
                        $grouped[$key]['extra_lines'][$prLine['id']] = $qty;
                        $grouped[$key]['specs'][] = "PR {$prLine['requisition_number']}";
                        if ($prLine['required_by_date'] !== null && ($grouped[$key]['requested_delivery_date'] === null || $prLine['required_by_date'] < $grouped[$key]['requested_delivery_date'])) {
                            $grouped[$key]['requested_delivery_date'] = $prLine['required_by_date'];
                        }
                    }
                }

                foreach ($grouped as $item) {
                    foreach ($item['extra_lines'] as $extraLineId => $extraQty) {
                        $extraConsolidatedLocks[$extraLineId] = $extraQty;
                    }

                    $rfqLines[] = [
                        'source_requisition_line_id' => $item['extra_lines'] === [] ? $item['source_requisition_line_id'] : null,
                        'internal_sku' => $item['internal_sku'],
                        'custom_item_code' => $item['internal_sku'],
                        'description' => $item['description'],
                        'technical_specifications' => count($item['specs']) > 1 ? 'Consolidated from: '.implode(', ', array_unique($item['specs'])) : null,
                        'quantity' => $item['quantity'],
                        'uom' => $item['uom'],
                        'target_unit_price' => $item['target_unit_price'],
                        'requested_delivery_date' => $item['requested_delivery_date'],
                    ];

                    if ($item['extra_lines'] !== []) {
                        // Lock primary line too when consolidated
                        $primaryId = $item['source_requisition_line_id'];
                        $primaryQty = collect($prLines)->firstWhere('id', $primaryId)['remaining_quantity'] ?? '0.0000';
                        $extraConsolidatedLocks[$primaryId] = $primaryQty;
                    }
                }
            } else {
                foreach ($prLines as $prLine) {
                    $rfqLines[] = [
                        'source_requisition_line_id' => $prLine['id'],
                        'internal_sku' => $prLine['internal_sku'],
                        'custom_item_code' => $prLine['internal_sku'],
                        'description' => $prLine['description'],
                        'technical_specifications' => "Source PR: {$prLine['requisition_number']}",
                        'quantity' => $prLine['remaining_quantity'],
                        'uom' => $prLine['uom'],
                        'target_unit_price' => Decimal::cmp($prLine['estimated_unit_price'], '0', 4) > 0 ? $prLine['estimated_unit_price'] : null,
                        'requested_delivery_date' => $prLine['required_by_date'],
                    ];
                }
            }

            $rfq = $this->createHandler->handle(new CreateRfqCommand(
                tenantId: $command->tenantId,
                actorUserId: $command->actorUserId,
                title: $command->title,
                description: $command->description,
                currencyCode: $currency,
                incoterms: $command->incoterms,
                submissionDeadline: $command->submissionDeadline,
                expectedDeliveryDate: $command->expectedDeliveryDate,
                evaluationWeights: $command->evaluationWeights,
                sealedBidding: $command->sealedBidding,
                purchaseRequisitionId: $singlePrId,
                costCenterId: $prLines[0]['cost_center_id'],
                notes: $command->notes,
                supplierIds: $command->supplierIds,
                lines: $rfqLines,
            ));

            if ($extraConsolidatedLocks !== []) {
                $this->requisitions->lockLinesForRfq($command->tenantId, $extraConsolidatedLocks);
            }

            return $rfq;
        });
    }
}
