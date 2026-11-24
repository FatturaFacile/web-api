<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Commands\UpdateRfqCommand;
use App\Domain\Procurement\Rfq\Contracts\RequisitionSourcingAdapterInterface;
use App\Domain\Procurement\Rfq\Enums\InvitationStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqLine;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use App\Domain\Procurement\Rfq\ValueObjects\EvaluationWeights;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Services\ProcurementAuditLedgerService;
use Modules\Procurement\Services\ProcurementService;
use Modules\Procurement\Support\Decimal;

/**
 * Updates a draft RequestForQuotation (header, lines, evaluation weights, and supplier invitations).
 */
final readonly class UpdateRfqHandler
{
    public function __construct(
        private ProcurementService $procurement,
        private ProcurementAuditLedgerService $auditLedger,
        private RequisitionSourcingAdapterInterface $requisitions,
    ) {}

    public function handle(UpdateRfqCommand $command): RequestForQuotation
    {
        return DB::transaction(function () use ($command): RequestForQuotation {
            /** @var RequestForQuotation $rfq */
            $rfq = RequestForQuotation::query()->whereKey($command->rfqId)->lockForUpdate()->firstOrFail();

            if (! $rfq->statusEnum()->canBeEdited()) {
                throw new ProcurementRuleViolation("An RFQ in status {$rfq->status} cannot be updated.", 'status');
            }

            $updates = [];

            if ($command->title !== null) {
                $updates['title'] = $command->title;
            }
            if ($command->description !== null) {
                $updates['description'] = $command->description;
            }
            if ($command->currencyCode !== null) {
                $updates['currency'] = strtoupper(trim($command->currencyCode));
            }
            if ($command->incoterms !== null) {
                $updates['incoterms'] = $command->incoterms;
            }
            if ($command->submissionDeadline !== null) {
                $deadline = $this->parseDeadline($command->submissionDeadline);
                $updates['submission_deadline'] = $deadline;
                $updates['response_deadline'] = $deadline->toDateString();
            }
            if ($command->expectedDeliveryDate !== null) {
                $updates['expected_delivery_date'] = $command->expectedDeliveryDate;
            }
            if ($command->evaluationWeights !== null) {
                $weights = EvaluationWeights::fromArray($command->evaluationWeights);
                $updates['evaluation_weights'] = $weights->toArray();
            }
            if ($command->sealedBidding !== null) {
                $updates['sealed_bidding'] = $command->sealedBidding;
            }
            if ($command->costCenterId !== null) {
                $updates['cost_center_id'] = $command->costCenterId;
            }
            if ($command->notes !== null) {
                $updates['notes'] = $command->notes;
            }

            if ($updates !== []) {
                $rfq->update($updates);
            }

            if ($command->lines !== null) {
                if ($command->lines === []) {
                    throw new ProcurementRuleViolation('A request for quotation needs at least one line.', 'lines');
                }

                $oldLines = $rfq->lines()->get();
                $releaseQuantities = [];
                foreach ($oldLines as $oldLine) {
                    if ($oldLine->purchase_requisition_line_id !== null) {
                        $prLineId = (string) $oldLine->purchase_requisition_line_id;
                        $releaseQuantities[$prLineId] = Decimal::add($releaseQuantities[$prLineId] ?? '0.0000', (string) $oldLine->quantity, 4);
                    }
                }

                if ($releaseQuantities !== []) {
                    $this->requisitions->releaseLinesFromRfq((string) $rfq->tenant_id, $releaseQuantities, (string) $rfq->id);
                }

                RfqLine::query()->where('rfq_id', $rfq->id)->forceDelete();

                $lockQuantities = [];
                foreach ($command->lines as $index => $line) {
                    $qty = Decimal::of($line['quantity'], 4);
                    $prLineId = $line['source_requisition_line_id'] ?? null;

                    if (is_string($prLineId) && $prLineId !== '') {
                        $lockQuantities[$prLineId] = Decimal::add($lockQuantities[$prLineId] ?? '0.0000', $qty, 4);
                    }

                    RfqLine::create([
                        'tenant_id' => $rfq->tenant_id,
                        'rfq_id' => $rfq->id,
                        'line_number' => $index + 1,
                        'purchase_requisition_line_id' => $prLineId,
                        'item_id' => $line['item_id'] ?? null,
                        'custom_item_code' => $line['custom_item_code'] ?? null,
                        'internal_sku' => $line['internal_sku'] ?? ($line['custom_item_code'] ?? null),
                        'description' => $line['description'],
                        'technical_specifications' => $line['technical_specifications'] ?? null,
                        'quantity' => $qty,
                        'uom' => $line['uom'] ?? 'PCS',
                        'target_unit_price' => isset($line['target_unit_price']) && $line['target_unit_price'] !== ''
                            ? Decimal::of($line['target_unit_price'], 4)
                            : null,
                        'required_by_date' => $line['requested_delivery_date'] ?? null,
                    ]);
                }

                if ($lockQuantities !== []) {
                    $this->requisitions->lockLinesForRfq((string) $rfq->tenant_id, $lockQuantities);
                }
            }

            if ($command->supplierIds !== null) {
                if ($command->supplierIds === []) {
                    throw new ProcurementRuleViolation('At least one qualified supplier must be selected.', 'supplier_ids');
                }

                $desiredIds = array_values(array_unique($command->supplierIds));
                $existing = $rfq->invitations()->get()->keyBy('supplier_id');

                foreach ($existing as $supplierId => $invitation) {
                    if (! in_array((string) $supplierId, $desiredIds, true)) {
                        $invitation->forceDelete();
                    }
                }

                foreach ($desiredIds as $supplierId) {
                    if ($existing->has($supplierId)) {
                        continue;
                    }

                    $supplier = Supplier::query()->where('tenant_id', $rfq->tenant_id)->findOrFail($supplierId);
                    $this->procurement->assertSupplierCanOrder($supplier);

                    RfqSupplierInvitation::create([
                        'tenant_id' => $rfq->tenant_id,
                        'rfq_id' => $rfq->id,
                        'supplier_id' => $supplier->id,
                        'status' => InvitationStatus::Pending->value,
                    ]);
                }
            }

            $this->auditLedger->record(
                action: 'RFQ_UPDATED',
                model: $rfq,
                payloadBefore: null,
                payloadAfter: ['rfq_number' => $rfq->rfq_number]
            );

            return $rfq->fresh(['lines', 'attachments', 'invitations.supplier', 'buyer']) ?? $rfq;
        });
    }

    private function parseDeadline(string $value): CarbonImmutable
    {
        $trimmed = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            return CarbonImmutable::parse($trimmed.' 23:59:59', 'UTC');
        }

        return CarbonImmutable::parse($trimmed)->utc();
    }
}
