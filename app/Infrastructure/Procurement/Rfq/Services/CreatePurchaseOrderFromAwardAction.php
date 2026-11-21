<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\Rfq\Services;

use App\Domain\Procurement\Rfq\Contracts\PurchaseOrderAwardCreatorInterface;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqLine;
use App\Domain\Procurement\Rfq\Models\SupplierBid;
use App\Domain\Procurement\Rfq\Models\SupplierBidLine;
use Carbon\CarbonImmutable;
use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Support\Decimal;

/**
 * Bridges Mod 045 RFQ awards to Mod 046 Purchase Order generation.
 */
final readonly class CreatePurchaseOrderFromAwardAction implements PurchaseOrderAwardCreatorInterface
{
    public function __construct(
        private ProcurementServiceInterface $procurementService,
    ) {}

    /**
     * @param  list<array{rfq_line: RfqLine, bid: SupplierBid, bid_line: SupplierBidLine}>  $awardedItems
     */
    public function execute(
        RequestForQuotation $rfq,
        string $supplierId,
        array $awardedItems,
        ?string $actorUserId,
        ?string $expectedDeliveryDate = null,
        ?string $notes = null,
    ): PurchaseOrder {
        /** @var SupplierBid $representativeBid */
        $representativeBid = $awardedItems[0]['bid'];
        $maxLeadTime = 0;
        $poLinesPayload = [];

        foreach ($awardedItems as $item) {
            /** @var RfqLine $rfqLine */
            $rfqLine = $item['rfq_line'];
            /** @var SupplierBidLine $bidLine */
            $bidLine = $item['bid_line'];

            $leadTime = max(0, (int) ($bidLine->lead_time_days ?? $representativeBid->lead_time_days ?? 1));
            if ($leadTime > $maxLeadTime) {
                $maxLeadTime = $leadTime;
            }

            $sku = $rfqLine->internal_sku ?? $rfqLine->custom_item_code;
            $catalogId = $sku !== null && $sku !== ''
                ? SupplierCatalog::query()
                    ->where('tenant_id', $rfq->tenant_id)
                    ->where('supplier_id', $supplierId)
                    ->where('internal_sku', $sku)
                    ->value('id')
                : null;

            $netUnitPrice = Decimal::cmp((string) $bidLine->net_unit_price, '0', 4) > 0
                ? (string) $bidLine->net_unit_price
                : (string) $bidLine->unit_price;

            $poLinesPayload[] = [
                'description' => (string) $rfqLine->description,
                'quantity_ordered' => (string) $bidLine->quantity,
                'unit_price' => $netUnitPrice,
                'tax_rate' => (string) ($bidLine->tax_rate ?? '22.00'),
                'uom' => (string) $rfqLine->uom,
                'supplier_catalog_id' => $catalogId !== null ? (string) $catalogId : null,
                'expected_delivery_date' => $bidLine->promised_delivery_date?->toDateString()
                    ?? $rfqLine->required_by_date?->toDateString()
                    ?? $expectedDeliveryDate,
            ];
        }

        $deliveryDate = $expectedDeliveryDate
            ?? $rfq->expected_delivery_date?->toDateString()
            ?? CarbonImmutable::now('UTC')->addDays(max(1, $maxLeadTime))->toDateString();

        $exchangeRate = (float) ($representativeBid->exchange_rate_to_rfq > 0 ? $representativeBid->exchange_rate_to_rfq : 1.0);
        $paymentTermsCode = $representativeBid->payment_terms_days > 0
            ? 'NET'.$representativeBid->payment_terms_days
            : null;

        $order = $this->procurementService->createPurchaseOrder(new CreatePurchaseOrderDTO(
            supplierId: $supplierId,
            orderDate: CarbonImmutable::now('UTC')->toDateString(),
            expectedDeliveryDate: $deliveryDate,
            lines: $poLinesPayload,
            requisitionId: $rfq->purchase_requisition_id,
            costCenterId: $rfq->cost_center_id,
            currency: strtoupper((string) ($representativeBid->currency !== '' ? $representativeBid->currency : $rfq->effectiveCurrencyCode())),
            exchangeRate: $exchangeRate,
            paymentTermsCode: $paymentTermsCode,
            incoterms: $representativeBid->incoterms ?? $rfq->incoterms,
            notes: $notes ?? "Awarded from {$rfq->rfq_number}",
            tenantId: (string) $rfq->tenant_id,
            companyId: $rfq->company_id,
            creatorUserId: $actorUserId,
        ));

        $order->update(['rfq_id' => $rfq->id]);

        $orderLinesByNumber = $order->lines->keyBy('line_number');
        foreach ($awardedItems as $index => $item) {
            /** @var PurchaseOrderLine|null $poLine */
            $poLine = $orderLinesByNumber->get($index + 1);
            if ($poLine !== null) {
                $poLine->update([
                    'rfq_line_id' => $item['rfq_line']->id,
                    'supplier_bid_line_id' => $item['bid_line']->id,
                ]);
            }
        }

        return $order->fresh(['lines', 'supplier']) ?? $order;
    }
}
