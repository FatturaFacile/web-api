<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\Tenant;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\Events\PurchaseOrderMilestoneEvent;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderDropship;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseOrderVirtualStockMovement;
use Modules\Procurement\Support\Decimal;

/**
 * Dropshipment (triangulation): the supplier ships straight to the customer of a sales order.
 *
 * 1. A sales order of type `dropship` generates the purchase order, linked to it line by line, with the delivery address
 *    of the final customer.
 * 2. The custom transport document (DDT) carries our company header, the supplier as origin and the customer as
 *    destination.
 * 3. The delivery notice of the supplier books the goods through the virtual warehouse (stock in from the supplier and
 *    out to the customer, valued at the order cost, no physical location) and authorises the active invoicing of the
 *    customer for what was delivered (Mod 038).
 */
final readonly class DropshipmentService
{
    public function __construct(
        private ProcurementService $procurement,
        private PurchasePricingService $pricing,
        private ProcurementNumberingService $numbering,
        private ProcurementAuditLedgerService $auditLedger,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * Generates the purchase order of a dropship sales order for the supplier. Calling it again for the same sales
     * order and supplier returns the existing order, so a retry never orders twice.
     *
     * @param  array{delivery_address: array<string, mixed>, expected_delivery_date?: string|null, currency?: string|null, exchange_rate?: string|float|null, lines?: array<int, array<string, mixed>>, notes?: string|null}  $data
     *
     * @throws ProcurementRuleViolation when the sales order is not a dropship order or a line cannot be ordered
     */
    public function createFromSalesOrder(string $salesOrderId, string $supplierId, array $data, ?string $actorId = null): PurchaseOrderDropship
    {
        return DB::transaction(function () use ($salesOrderId, $supplierId, $data, $actorId): PurchaseOrderDropship {
            $salesOrder = SalesOrder::query()->with(['items', 'customer'])->whereKey($salesOrderId)->lockForUpdate()->first();

            if ($salesOrder === null) {
                throw new ProcurementRuleViolation('The sales order does not exist in the active company.', 'sales_order_id');
            }

            if ((string) $salesOrder->order_type !== 'dropship') {
                throw new ProcurementRuleViolation('Only a sales order of type dropship can be fulfilled by a supplier shipment.', 'sales_order_id');
            }

            $existing = PurchaseOrder::query()->where('sales_order_id', $salesOrder->id)->where('supplier_id', $supplierId)->where('is_dropship', true)->whereNot('status', PurchaseOrderStatus::Cancelled->value)->first();

            if ($existing !== null) {
                return PurchaseOrderDropship::query()->where('purchase_order_id', $existing->id)->firstOrFail();
            }

            $address = $this->recipient($data['delivery_address'], $salesOrder);
            $items = $salesOrder->items->keyBy(static fn ($item): string => (string) $item->id);
            $requested = collect($data['lines'] ?? [])->keyBy(static fn (array $line): string => (string) ($line['sales_order_item_id'] ?? ''));
            $lines = [];

            foreach ($requested->isEmpty() ? $items->map(static fn ($item): array => ['sales_order_item_id' => (string) $item->id]) : $requested as $itemId => $line) {
                $item = $items->get((string) $itemId);

                if ($item === null) {
                    throw new ProcurementRuleViolation('A sales order line does not belong to the sales order.', 'lines');
                }

                $lines[] = [
                    ...$line,
                    'description' => $line['description'] ?? (string) $item->description,
                    'quantity_ordered' => $line['quantity_ordered'] ?? (string) $item->quantity,
                    'sales_order_line_id' => (string) $item->id,
                    'source_line_id' => (string) $item->id,
                    'expected_delivery_date' => $line['expected_delivery_date'] ?? $data['expected_delivery_date'] ?? null,
                ];
            }

            if ($lines === []) {
                throw new ProcurementRuleViolation('The sales order has no lines to order.', 'lines');
            }

            $expected = $data['expected_delivery_date'] ?? $salesOrder->requested_delivery_date?->toDateString() ?? now()->addDays(7)->toDateString();

            $order = $this->procurement->createPurchaseOrder(new CreatePurchaseOrderDTO(
                supplierId: $supplierId,
                orderDate: now()->toDateString(),
                expectedDeliveryDate: $expected,
                lines: $lines,
                orderType: 'dropship',
                currency: (string) ($data['currency'] ?? $salesOrder->currency ?? 'EUR'),
                exchangeRate: (float) ($data['exchange_rate'] ?? 1),
                shippingAddress: $address,
                notes: $data['notes'] ?? null,
                tenantId: (string) $salesOrder->tenant_id,
                creatorUserId: $actorId,
            ));

            $order->update(['sales_order_id' => $salesOrder->id, 'is_dropship' => true, 'source_type' => 'sales_order', 'source_id' => $salesOrder->id]);

            $dropship = PurchaseOrderDropship::create([
                'tenant_id' => $order->tenant_id,
                'purchase_order_id' => $order->id,
                'sales_order_id' => $salesOrder->id,
                'status' => 'pending',
                'recipient_name' => $address['name'],
                'recipient_street' => $address['street'],
                'recipient_house_number' => $address['house_number'] ?? null,
                'recipient_postal_code' => $address['postal_code'],
                'recipient_city' => $address['city'],
                'recipient_region' => $address['region'] ?? null,
                'recipient_country_code' => $address['country_code'],
                'recipient_phone' => $address['phone'] ?? null,
                'recipient_email' => $address['email'] ?? null,
                'delivery_notes' => $address['delivery_notes'] ?? null,
            ]);

            $this->auditLedger->record(action: 'DROPSHIPMENT_ORDER_CREATED', model: $order, payloadBefore: null, payloadAfter: ['sales_order_id' => $salesOrder->id, 'recipient' => $address['name']]);

            return $dropship;
        });
    }

    /**
     * Issues the transport document with the header of our company. Allowed once the supplier holds the order; it is
     * numbered once, a second call returns the same document.
     *
     * @param  array{carrier?: string|null, tracking_number?: string|null, packages?: int|null, gross_weight?: string|null, transport_by?: string|null, shipment_date?: string|null}  $transport
     *
     * @throws ProcurementRuleViolation when the order is not yet with the supplier
     */
    public function issueDdt(PurchaseOrderDropship $dropship, array $transport = []): PurchaseOrderDropship
    {
        return DB::transaction(function () use ($dropship, $transport): PurchaseOrderDropship {
            $locked = PurchaseOrderDropship::query()->whereKey($dropship->id)->lockForUpdate()->firstOrFail();

            if ($locked->ddt_number !== null) {
                return $locked;
            }

            $order = PurchaseOrder::query()->with(['lines', 'supplier'])->findOrFail($locked->purchase_order_id);

            if (! in_array((string) $order->status, PurchaseOrderStatus::receivableValues(), true)) {
                throw new ProcurementRuleViolation("An order in status {$order->status} is not yet with the supplier: the transport document cannot be issued.", 'status');
            }

            $tenant = Tenant::query()->findOrFail($order->tenant_id);
            $company = (array) ($tenant->settings['preferences']['company'] ?? []);
            $number = $this->numbering->next(ProcurementNumberingService::DropshipDdt, (string) $order->tenant_id);

            $payload = [
                'document_type' => 'dropship_ddt',
                'number' => $number,
                'issued_on' => now()->toDateString(),
                'sender' => [
                    'name' => $tenant->legal_name ?? $tenant->name,
                    'vat_number' => $tenant->vat_number,
                    'fiscal_code' => $tenant->fiscal_code,
                    'street' => trim((string) ($company['street'] ?? $company['address_line_1'] ?? '').' '.(string) ($company['house_number'] ?? '')),
                    'postal_code' => $company['postal_code'] ?? null,
                    'city' => $company['city'] ?? null,
                    'country_code' => $tenant->country,
                ],
                'origin' => [
                    'supplier_id' => (string) $order->supplier_id,
                    'supplier_name' => $order->supplier?->company_name,
                    'purchase_order' => (string) ($order->po_number ?? $order->order_number),
                    'supplier_confirmation_number' => $order->supplier_confirmation_number,
                    'supplier_shipment_reference' => $locked->supplier_shipment_reference,
                ],
                'destination' => [
                    'name' => $locked->recipient_name,
                    'street' => trim($locked->recipient_street.' '.(string) $locked->recipient_house_number),
                    'postal_code' => $locked->recipient_postal_code,
                    'city' => $locked->recipient_city,
                    'region' => $locked->recipient_region,
                    'country_code' => $locked->recipient_country_code,
                    'phone' => $locked->recipient_phone,
                    'delivery_notes' => $locked->delivery_notes,
                ],
                'sales_order_id' => $locked->sales_order_id,
                'transport' => $transport,
                'lines' => $order->lines->map(static fn (PurchaseOrderLine $line): array => [
                    'line_number' => $line->line_number,
                    'description' => $line->description,
                    'quantity' => (string) $line->quantity_ordered,
                    'uom' => $line->uom,
                    'quantity_base' => (string) $line->quantity_base_ordered,
                    'base_uom' => $line->base_uom,
                ])->all(),
            ];

            $locked->update(['ddt_number' => $number, 'ddt_issued_at' => now(), 'ddt_payload' => $payload, 'status' => $locked->status === 'pending' ? 'ddt_issued' : $locked->status]);
            $this->auditLedger->record(action: 'DROPSHIPMENT_DDT_ISSUED', model: $order, payloadBefore: null, payloadAfter: ['ddt_number' => $number]);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Records the delivery notice of the supplier: virtual stock in and out, received quantities in both units, order
     * status, and the authorisation to invoice the customer for what was delivered.
     *
     * @param  array{delivered_at?: string|null, delivery_proof_reference?: string|null, supplier_shipment_reference?: string|null, lines?: array<int, array{purchase_order_line_id: string, quantity_delivered: string|float|int}>}  $data
     *
     * @throws ProcurementRuleViolation when nothing can be delivered or a quantity exceeds what is open
     */
    public function recordDelivery(PurchaseOrderDropship $dropship, array $data): PurchaseOrderDropship
    {
        return DB::transaction(function () use ($dropship, $data): PurchaseOrderDropship {
            $locked = PurchaseOrderDropship::query()->whereKey($dropship->id)->lockForUpdate()->firstOrFail();
            $order = PurchaseOrder::query()->whereKey($locked->purchase_order_id)->lockForUpdate()->firstOrFail();

            if (! in_array((string) $order->status, PurchaseOrderStatus::receivableValues(), true)) {
                throw new ProcurementRuleViolation("An order in status {$order->status} cannot receive a delivery notice.", 'status');
            }

            $orderLines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->lockForUpdate()->get()->keyBy(static fn (PurchaseOrderLine $line): string => (string) $line->id);
            $given = collect($data['lines'] ?? [])->keyBy(static fn (array $line): string => (string) $line['purchase_order_line_id']);

            foreach ($given->keys() as $lineId) {
                if (! $orderLines->has($lineId)) {
                    throw new ProcurementRuleViolation('A delivered line does not belong to the purchase order.', 'lines');
                }
            }

            $movedAt = $data['delivered_at'] ?? now()->toDateTimeString();
            $rate = Decimal::of($order->exchange_rate ?? '1', 6);
            $delivered = [];

            foreach ($orderLines as $lineId => $line) {
                $open = Decimal::sub(Decimal::sub((string) $line->quantity_ordered, (string) $line->quantity_cancelled, 4), (string) $line->quantity_received, 4);
                $quantity = $given->isEmpty() ? $open : Decimal::of($given->get($lineId)['quantity_delivered'] ?? '0', 4);

                if (! Decimal::isPositive($quantity)) {
                    continue;
                }

                if (Decimal::cmp($quantity, $open, 4) > 0) {
                    throw new ProcurementRuleViolation("Line {$line->line_number}: the delivered quantity exceeds the open quantity.", 'lines');
                }

                $factor = (string) $line->uom_conversion_factor;
                $base = $this->pricing->toBase($quantity, $factor);
                $unitCostBase = Decimal::mul($this->pricing->pricePerBaseUnit((string) $line->unit_price, $factor), $rate, 6);
                $valued = Decimal::round(bcmul($base, $unitCostBase, 8), 2);

                foreach (['in', 'out'] as $direction) {
                    PurchaseOrderVirtualStockMovement::create([
                        'tenant_id' => $order->tenant_id,
                        'purchase_order_dropship_id' => $locked->id,
                        'purchase_order_line_id' => $line->id,
                        'direction' => $direction,
                        'quantity_base' => $base,
                        'base_uom' => $line->base_uom,
                        'unit_cost_base' => $unitCostBase,
                        'valued_amount' => $valued,
                        'moved_at' => $movedAt,
                    ]);
                }

                $received = Decimal::add((string) $line->quantity_received, $quantity, 4);
                $line->update([
                    'quantity_received' => $received,
                    'quantity_base_received' => $this->pricing->toBase($received, $factor),
                    'is_closed' => Decimal::cmp($received, Decimal::sub((string) $line->quantity_ordered, (string) $line->quantity_cancelled, 4), 4) >= 0,
                ]);

                $delivered[] = ['purchase_order_line_id' => $lineId, 'sales_order_line_id' => $line->sales_order_line_id, 'quantity' => $quantity, 'quantity_base' => $base];
            }

            if ($delivered === []) {
                throw new ProcurementRuleViolation('There is nothing open to deliver on this order.', 'lines');
            }

            $this->procurement->refreshReceivingStatus($order->refresh());
            $complete = ! PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->where('is_closed', false)->exists();
            $number = (string) ($order->po_number ?? $order->order_number);
            $firstAuthorisation = ! $locked->billing_authorized;
            $reference = $locked->billing_authorization_reference ?? 'DSB-'.$number;

            $locked->update([
                'status' => $complete ? 'delivered' : 'partially_delivered',
                'delivered_at' => $movedAt,
                'delivery_proof_reference' => $data['delivery_proof_reference'] ?? $locked->delivery_proof_reference,
                'supplier_shipment_reference' => $data['supplier_shipment_reference'] ?? $locked->supplier_shipment_reference,
                'billing_authorized' => true,
                'billing_authorized_at' => $locked->billing_authorized_at ?? now(),
                'billing_authorization_reference' => $reference,
            ]);

            if ($locked->sales_order_id !== null) {
                DB::table('sales_orders')->where('id', $locked->sales_order_id)->where('tenant_id', $order->tenant_id)
                    ->update(['billing_authorized_at' => now(), 'billing_authorization_reference' => $reference, 'updated_at' => now()]);
            }

            $this->auditLedger->record(action: 'DROPSHIPMENT_DELIVERED', model: $order, payloadBefore: null, payloadAfter: ['complete' => $complete, 'reference' => $reference, 'lines' => count($delivered)]);

            $payload = ['sales_order_id' => $locked->sales_order_id, 'lines' => $delivered, 'billing_reference' => $reference, 'complete' => $complete];
            $this->dispatcher->publishAndDispatch(new PurchaseOrderMilestoneEvent(PurchaseOrderMilestoneEvent::DropshipDelivered, (string) $order->id, $number, (string) $order->tenant_id, (string) ($order->company_id ?? $order->tenant_id), $payload));
            $this->dispatcher->publishAndDispatch(new PurchaseOrderMilestoneEvent(PurchaseOrderMilestoneEvent::BillingAuthorized, (string) $order->id, $number, (string) $order->tenant_id, (string) ($order->company_id ?? $order->tenant_id), [...$payload, 'first_authorisation' => $firstAuthorisation]));

            return $locked->fresh(['movements']) ?? $locked;
        });
    }

    /**
     * Final recipient: the given address, with the customer name as default.
     *
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     *
     * @throws ProcurementRuleViolation when a mandatory part of the address is missing
     */
    private function recipient(array $address, SalesOrder $salesOrder): array
    {
        $customer = $salesOrder->customer;
        $address['name'] ??= $customer?->company_name ?: trim((string) $customer?->first_name.' '.(string) $customer?->last_name);

        foreach (['name', 'street', 'postal_code', 'city', 'country_code'] as $field) {
            if (empty($address[$field])) {
                throw new ProcurementRuleViolation("The delivery address needs a {$field}.", "delivery_address.{$field}");
            }
        }

        $address['country_code'] = strtoupper((string) $address['country_code']);

        return $address;
    }
}
