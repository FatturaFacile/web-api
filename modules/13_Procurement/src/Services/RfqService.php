<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Support\Facades\DB;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\Enums\PurchaseRequisitionStatus;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\RfqLine;
use Modules\Procurement\Models\RfqSupplier;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Models\SupplierQuote;
use Modules\Procurement\Models\SupplierQuoteLine;
use Modules\Procurement\Support\Decimal;

/**
 * Requests for quotation: an approved requisition (or a list of items) is sent to several suppliers, their quotes are
 * recorded and compared, and the winning quote becomes a purchase order at the quoted prices.
 */
final readonly class RfqService
{
    public function __construct(
        private ProcurementNumberingService $numbering,
        private ProcurementService $procurement,
        private ProcurementAuditLedgerService $auditLedger,
    ) {}

    /**
     * Creates a draft request for quotation. Without explicit lines the lines of the requisition are copied; the
     * requisition must be approved and goes into sourcing.
     *
     * @param  array{title: string, response_deadline: string, currency?: ?string, purchase_requisition_id?: ?string, cost_center_id?: ?string, notes?: ?string, supplier_ids: list<string>, lines?: list<array{description: string, quantity: numeric-string|float|int, uom?: ?string, internal_sku?: ?string, required_by_date?: ?string, purchase_requisition_line_id?: ?string}>}  $data
     *
     * @throws ProcurementRuleViolation when the requisition is not approved or a supplier cannot be invited
     */
    public function create(string $tenantId, array $data, ?string $userId): Rfq
    {
        return DB::transaction(function () use ($tenantId, $data, $userId): Rfq {
            $requisition = null;

            if (! empty($data['purchase_requisition_id'])) {
                $requisition = PurchaseRequisition::query()->with('lines.catalog')->whereKey($data['purchase_requisition_id'])->lockForUpdate()->firstOrFail();
                PurchaseRequisitionStatus::assertTransition((string) $requisition->status, PurchaseRequisitionStatus::Sourcing);
            }

            $lines = $data['lines'] ?? [];

            if ($lines === [] && $requisition !== null) {
                $lines = $requisition->lines->map(static fn ($line): array => [
                    'description' => (string) $line->description,
                    'quantity' => (string) $line->quantity_requested,
                    'uom' => (string) $line->uom,
                    'internal_sku' => $line->catalog?->internal_sku,
                    'required_by_date' => $requisition->required_by_date?->toDateString(),
                    'purchase_requisition_line_id' => (string) $line->id,
                ])->all();
            }

            if ($lines === []) {
                throw new ProcurementRuleViolation('A request for quotation needs at least one line.', 'lines');
            }

            $rfq = Rfq::create([
                'tenant_id' => $tenantId,
                'rfq_number' => $this->numbering->next(ProcurementNumberingService::Rfq, $tenantId),
                'title' => $data['title'],
                'purchase_requisition_id' => $requisition?->id,
                'status' => 'draft',
                'response_deadline' => $data['response_deadline'],
                'currency' => strtoupper((string) ($data['currency'] ?? $requisition->currency ?? 'EUR')),
                'cost_center_id' => $data['cost_center_id'] ?? $requisition?->cost_center_id,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach (array_values($lines) as $index => $line) {
                RfqLine::create([
                    'tenant_id' => $tenantId,
                    'rfq_id' => $rfq->id,
                    'line_number' => $index + 1,
                    'purchase_requisition_line_id' => $line['purchase_requisition_line_id'] ?? null,
                    'internal_sku' => $line['internal_sku'] ?? null,
                    'description' => $line['description'],
                    'quantity' => Decimal::of($line['quantity'], 4),
                    'uom' => $line['uom'] ?? 'PCE',
                    'required_by_date' => $line['required_by_date'] ?? null,
                ]);
            }

            foreach (array_unique($data['supplier_ids']) as $supplierId) {
                $supplier = Supplier::query()->findOrFail($supplierId);
                $this->procurement->assertSupplierCanOrder($supplier);
                RfqSupplier::create(['tenant_id' => $tenantId, 'rfq_id' => $rfq->id, 'supplier_id' => $supplier->id, 'status' => 'invited']);
            }

            $requisition?->update(['status' => PurchaseRequisitionStatus::Sourcing->value]);
            $this->auditLedger->record(action: 'RFQ_CREATED', model: $rfq, payloadBefore: null, payloadAfter: ['rfq_number' => $rfq->rfq_number, 'suppliers' => count($data['supplier_ids'])]);

            return $rfq->fresh(['lines', 'invitedSuppliers']) ?? $rfq;
        });
    }

    /**
     * Issues the request to the invited suppliers.
     */
    public function issue(Rfq $rfq): Rfq
    {
        return DB::transaction(function () use ($rfq): Rfq {
            $locked = $this->lockInStatus($rfq, ['draft']);
            $locked->update(['status' => 'issued', 'issue_date' => now()->toDateString()]);
            RfqSupplier::query()->where('rfq_id', $locked->id)->update(['invited_at' => now()]);

            return $locked->fresh(['lines', 'invitedSuppliers']) ?? $locked;
        });
    }

    /**
     * Records the quote of an invited supplier, one per supplier, received by the response deadline.
     *
     * @param  array{supplier_id: string, quote_reference?: ?string, quote_date: string, valid_until?: ?string, lead_time_days?: ?int, notes?: ?string, lines: list<array{rfq_line_id: string, unit_price: numeric-string|float|int, quantity?: numeric-string|float|int|null, tax_rate?: numeric-string|float|int|null, lead_time_days?: ?int}>}  $data
     *
     * @throws ProcurementRuleViolation when the supplier was not invited, already quoted, or the deadline passed
     */
    public function recordQuote(Rfq $rfq, array $data): SupplierQuote
    {
        return DB::transaction(function () use ($rfq, $data): SupplierQuote {
            $locked = $this->lockInStatus($rfq, ['issued']);
            $invitation = RfqSupplier::query()->where('rfq_id', $locked->id)->where('supplier_id', $data['supplier_id'])->lockForUpdate()->first();

            if ($invitation === null) {
                throw new ProcurementRuleViolation('The supplier was not invited to this request for quotation.', 'supplier_id');
            }

            if (SupplierQuote::query()->where('rfq_id', $locked->id)->where('supplier_id', $data['supplier_id'])->exists()) {
                throw new ProcurementRuleViolation('The supplier already quoted on this request for quotation.', 'supplier_id');
            }

            if ($data['quote_date'] > $locked->response_deadline->toDateString()) {
                throw new ProcurementRuleViolation('The quote arrived after the response deadline.', 'quote_date');
            }

            $quote = SupplierQuote::create([
                'tenant_id' => $locked->tenant_id,
                'rfq_id' => $locked->id,
                'supplier_id' => $data['supplier_id'],
                'quote_reference' => $data['quote_reference'] ?? null,
                'quote_date' => $data['quote_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'currency' => $locked->currency,
                'lead_time_days' => $data['lead_time_days'] ?? null,
                'status' => 'received',
                'notes' => $data['notes'] ?? null,
            ]);

            $total = '0.00';

            foreach ($data['lines'] as $index => $line) {
                $rfqLine = RfqLine::query()->whereKey($line['rfq_line_id'])->where('rfq_id', $locked->id)->first();

                if ($rfqLine === null) {
                    throw new ProcurementRuleViolation('Quote line '.($index + 1).' names a line of another request.', "lines.{$index}.rfq_line_id");
                }

                $quantity = Decimal::of($line['quantity'] ?? $rfqLine->quantity, 4);
                $lineTotal = Decimal::mul($quantity, Decimal::of($line['unit_price'], 4), 2);
                $total = Decimal::add($total, $lineTotal, 2);

                SupplierQuoteLine::create([
                    'tenant_id' => $locked->tenant_id,
                    'supplier_quote_id' => $quote->id,
                    'rfq_line_id' => $rfqLine->id,
                    'quantity' => $quantity,
                    'unit_price' => Decimal::of($line['unit_price'], 4),
                    'tax_rate' => Decimal::of($line['tax_rate'] ?? '22', 2),
                    'line_total' => $lineTotal,
                    'lead_time_days' => $line['lead_time_days'] ?? $data['lead_time_days'] ?? null,
                ]);
            }

            $quote->update(['total_amount' => $total]);
            $invitation->update(['status' => 'responded', 'responded_at' => now()]);

            return $quote->fresh('lines') ?? $quote;
        });
    }

    /**
     * Side-by-side comparison: per line the offers cheapest first, per quote the total, coverage and lead time, and
     * the recommended quote (the cheapest among those covering every line).
     *
     * @return array{rfq_id: string, lines: list<array<string, mixed>>, quotes: list<array<string, mixed>>, recommended_quote_id: ?string}
     */
    public function compare(Rfq $rfq): array
    {
        $rfq->load(['lines', 'quotes.lines', 'quotes.supplier']);
        $lineCount = $rfq->lines->count();

        $quotes = $rfq->quotes->map(static fn (SupplierQuote $quote): array => [
            'quote_id' => (string) $quote->id,
            'supplier_id' => (string) $quote->supplier_id,
            'supplier_name' => $quote->supplier?->company_name,
            'supplier_rating' => $quote->supplier?->rating,
            'total_amount' => (string) $quote->total_amount,
            'lead_time_days' => $quote->lead_time_days,
            'valid_until' => $quote->valid_until?->toDateString(),
            'lines_quoted' => $quote->lines->count(),
            'covers_all_lines' => $quote->lines->pluck('rfq_line_id')->unique()->count() === $lineCount,
            'status' => (string) $quote->status,
        ])->sortBy(static fn (array $quote): string => str_pad($quote['total_amount'], 20, '0', STR_PAD_LEFT))->values();

        $lines = $rfq->lines->map(static function (RfqLine $line) use ($rfq): array {
            $offers = $rfq->quotes
                ->flatMap(static fn (SupplierQuote $quote) => $quote->lines->where('rfq_line_id', $line->id)->map(static fn (SupplierQuoteLine $quoteLine): array => [
                    'quote_id' => (string) $quote->id,
                    'supplier_id' => (string) $quote->supplier_id,
                    'unit_price' => (string) $quoteLine->unit_price,
                    'line_total' => (string) $quoteLine->line_total,
                    'lead_time_days' => $quoteLine->lead_time_days,
                ]))
                ->sortBy(static fn (array $offer): string => str_pad($offer['unit_price'], 20, '0', STR_PAD_LEFT))
                ->values();

            return [
                'rfq_line_id' => (string) $line->id,
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                'offers' => $offers->all(),
                'best_unit_price' => $offers->first()['unit_price'] ?? null,
            ];
        });

        return [
            'rfq_id' => (string) $rfq->id,
            'lines' => $lines->all(),
            'quotes' => $quotes->all(),
            'recommended_quote_id' => $quotes->firstWhere('covers_all_lines', true)['quote_id'] ?? null,
        ];
    }

    /**
     * Awards the request to a quote: a draft purchase order is created at the quoted prices (catalogue entries of the
     * supplier are matched by internal SKU), the other quotes are rejected and the requisition is ordered.
     *
     * @throws ProcurementRuleViolation when the quote does not belong to the request or is expired
     */
    public function award(Rfq $rfq, string $quoteId, ?string $userId, ?string $expectedDeliveryDate = null): PurchaseOrder
    {
        return DB::transaction(function () use ($rfq, $quoteId, $userId, $expectedDeliveryDate): PurchaseOrder {
            $locked = $this->lockInStatus($rfq, ['issued']);
            $quote = SupplierQuote::query()->with('lines.rfqLine')->whereKey($quoteId)->where('rfq_id', $locked->id)->first();

            if ($quote === null) {
                throw new ProcurementRuleViolation('The quote does not belong to this request for quotation.', 'supplier_quote_id');
            }

            if ($quote->valid_until !== null && $quote->valid_until->toDateString() < now()->toDateString()) {
                throw new ProcurementRuleViolation('The quote expired: ask the supplier to renew it.', 'supplier_quote_id');
            }

            $leadTime = (int) ($quote->lead_time_days ?? 0);
            $lines = $quote->lines->map(static function (SupplierQuoteLine $line) use ($quote): array {
                $sku = $line->rfqLine?->internal_sku;
                $catalogId = $sku !== null ? SupplierCatalog::query()->where('supplier_id', $quote->supplier_id)->where('internal_sku', $sku)->value('id') : null;

                return [
                    'description' => (string) $line->rfqLine?->description,
                    'quantity_ordered' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price,
                    'tax_rate' => (string) $line->tax_rate,
                    'uom' => (string) ($line->rfqLine->uom ?? 'PCE'),
                    'supplier_catalog_id' => $catalogId !== null ? (string) $catalogId : null,
                ];
            })->all();

            $order = $this->procurement->createPurchaseOrder(new CreatePurchaseOrderDTO(
                supplierId: (string) $quote->supplier_id,
                orderDate: now()->toDateString(),
                expectedDeliveryDate: $expectedDeliveryDate ?? now()->addDays(max(1, $leadTime))->toDateString(),
                lines: $lines,
                requisitionId: $locked->purchase_requisition_id,
                costCenterId: $locked->cost_center_id,
                currency: (string) $locked->currency,
                notes: "Awarded from {$locked->rfq_number}",
                tenantId: (string) $locked->tenant_id,
                creatorUserId: $userId,
            ));

            $order->update(['rfq_id' => $locked->id]);
            $quote->update(['status' => 'awarded']);
            SupplierQuote::query()->where('rfq_id', $locked->id)->whereKeyNot($quote->id)->update(['status' => 'rejected']);
            $locked->update(['status' => 'awarded', 'awarded_supplier_id' => $quote->supplier_id, 'awarded_quote_id' => $quote->id, 'purchase_order_id' => $order->id]);

            if ($locked->purchase_requisition_id !== null) {
                PurchaseRequisition::query()->whereKey($locked->purchase_requisition_id)->update(['status' => PurchaseRequisitionStatus::Ordered->value]);
            }

            $this->auditLedger->record(action: 'RFQ_AWARDED', model: $locked, payloadBefore: ['status' => 'issued'], payloadAfter: ['quote_id' => $quote->id, 'purchase_order_id' => $order->id]);

            return $order;
        });
    }

    /**
     * Cancels an open request; the requisition goes back to approved.
     */
    public function cancel(Rfq $rfq): Rfq
    {
        return DB::transaction(function () use ($rfq): Rfq {
            $locked = $this->lockInStatus($rfq, ['draft', 'issued']);
            $locked->update(['status' => 'cancelled']);

            if ($locked->purchase_requisition_id !== null) {
                PurchaseRequisition::query()->whereKey($locked->purchase_requisition_id)->where('status', PurchaseRequisitionStatus::Sourcing->value)->update(['status' => PurchaseRequisitionStatus::Approved->value]);
            }

            return $locked;
        });
    }

    /**
     * @param  list<string>  $statuses
     *
     * @throws ProcurementRuleViolation when the request is in another status
     */
    private function lockInStatus(Rfq $rfq, array $statuses): Rfq
    {
        $locked = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $statuses, true)) {
            throw new ProcurementRuleViolation("A request for quotation in status {$locked->status} does not allow this operation.", 'status');
        }

        return $locked;
    }
}
