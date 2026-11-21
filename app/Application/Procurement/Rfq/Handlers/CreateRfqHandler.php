<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Commands\CreateRfqCommand;
use App\Domain\Procurement\Rfq\Contracts\RequisitionSourcingAdapterInterface;
use App\Domain\Procurement\Rfq\Enums\InvitationStatus;
use App\Domain\Procurement\Rfq\Enums\RfqStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqLine;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use App\Domain\Procurement\Rfq\ValueObjects\EvaluationWeights;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Services\ProcurementAuditLedgerService;
use Modules\Procurement\Services\ProcurementNumberingService;
use Modules\Procurement\Services\ProcurementService;
use Modules\Procurement\Support\Decimal;

/**
 * Creates a draft RequestForQuotation from scratch or from a linked PurchaseRequisition.
 */
final readonly class CreateRfqHandler
{
    public function __construct(
        private ProcurementNumberingService $numbering,
        private ProcurementService $procurement,
        private ProcurementAuditLedgerService $auditLedger,
        private RequisitionSourcingAdapterInterface $requisitions,
    ) {}

    public function handle(CreateRfqCommand $command): RequestForQuotation
    {
        return DB::transaction(function () use ($command): RequestForQuotation {
            $weights = EvaluationWeights::fromArray($command->evaluationWeights);
            $deadline = $this->parseDeadline($command->submissionDeadline);
            $lines = $command->lines;
            $currency = strtoupper(trim($command->currencyCode));
            $costCenterId = $command->costCenterId;

            if ($lines === [] && $command->purchaseRequisitionId !== null) {
                $quotableLines = $this->requisitions->getQuotableLines($command->tenantId, [$command->purchaseRequisitionId]);
                foreach ($quotableLines as $prLine) {
                    $lines[] = [
                        'source_requisition_line_id' => $prLine['id'],
                        'internal_sku' => $prLine['internal_sku'],
                        'description' => $prLine['description'],
                        'quantity' => $prLine['remaining_quantity'],
                        'uom' => $prLine['uom'],
                        'target_unit_price' => Decimal::cmp($prLine['estimated_unit_price'], '0', 4) > 0 ? $prLine['estimated_unit_price'] : null,
                        'requested_delivery_date' => $prLine['required_by_date'],
                    ];
                }

                if ($currency === '' && isset($quotableLines[0]['currency'])) {
                    $currency = $quotableLines[0]['currency'];
                }
                if ($costCenterId === null && isset($quotableLines[0]['cost_center_id'])) {
                    $costCenterId = $quotableLines[0]['cost_center_id'];
                }
            }

            if ($lines === []) {
                throw new ProcurementRuleViolation('A request for quotation needs at least one line.', 'lines');
            }

            $rfq = RequestForQuotation::create([
                'tenant_id' => $command->tenantId,
                'rfq_number' => $this->numbering->next(ProcurementNumberingService::Rfq, $command->tenantId),
                'title' => $command->title,
                'description' => $command->description,
                'purchase_requisition_id' => $command->purchaseRequisitionId,
                'status' => RfqStatus::Draft->value,
                'response_deadline' => $deadline->toDateString(),
                'submission_deadline' => $deadline,
                'expected_delivery_date' => $command->expectedDeliveryDate,
                'currency' => $currency !== '' ? $currency : 'EUR',
                'incoterms' => $command->incoterms,
                'evaluation_weights' => $weights->toArray(),
                'sealed_bidding' => $command->sealedBidding,
                'buyer_user_id' => $command->actorUserId,
                'cost_center_id' => $costCenterId,
                'notes' => $command->notes,
                'created_by' => $command->actorUserId,
            ]);

            $lockQuantities = [];

            foreach ($lines as $index => $line) {
                $qty = Decimal::of($line['quantity'], 4);
                $prLineId = $line['source_requisition_line_id'] ?? null;

                if (is_string($prLineId) && $prLineId !== '') {
                    $lockQuantities[$prLineId] = Decimal::add($lockQuantities[$prLineId] ?? '0.0000', $qty, 4);
                }

                RfqLine::create([
                    'tenant_id' => $command->tenantId,
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
                $this->requisitions->lockLinesForRfq($command->tenantId, $lockQuantities);
            }

            foreach (array_values(array_unique($command->supplierIds)) as $supplierId) {
                $supplier = Supplier::query()->where('tenant_id', $command->tenantId)->findOrFail($supplierId);
                $this->procurement->assertSupplierCanOrder($supplier);

                RfqSupplierInvitation::create([
                    'tenant_id' => $command->tenantId,
                    'rfq_id' => $rfq->id,
                    'supplier_id' => $supplier->id,
                    'status' => InvitationStatus::Pending->value,
                ]);
            }

            $this->auditLedger->record(
                action: 'RFQ_CREATED',
                model: $rfq,
                payloadBefore: null,
                payloadAfter: [
                    'rfq_number' => $rfq->rfq_number,
                    'suppliers' => count(array_unique($command->supplierIds)),
                    'lines' => count($lines),
                ]
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
