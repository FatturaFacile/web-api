<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Services;

use App\Models\Product;
use Modules\Procurement\Actions\CreatePurchaseOrderAction;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Subcontracting\Exceptions\SubcontractingException;
use Modules\Subcontracting\Models\SubcontractingOrder;

/**
 * Mod 046 hand-off: the external processing service of a passive order is bought with a purchase order of type "subcontract".
 * The order can link an existing purchase order or have one drafted from its service rate.
 */
final class ServicePurchaseOrderGateway
{
    public function __construct(private readonly CreatePurchaseOrderAction $create) {}

    /** The purchase order exists in the active company and belongs to the vendor of the job. */
    public function assertLinkable(string $tenantId, string $purchaseOrderId, string $supplierId): void
    {
        $order = PurchaseOrder::query()->where('tenant_id', $tenantId)->whereKey($purchaseOrderId)->first();

        if (! $order instanceof PurchaseOrder) {
            throw new SubcontractingException('purchase_order_not_found');
        }
        if ((string) $order->supplier_id !== $supplierId) {
            throw new SubcontractingException('purchase_order_supplier_mismatch');
        }
    }

    public function draftFor(SubcontractingOrder $order): PurchaseOrder
    {
        $productName = (string) (Product::query()->whereKey($order->output_product_id)->value('name') ?? $order->output_product_id);
        $date = now()->toDateString();

        return $this->create->execute(new CreatePurchaseOrderDTO(
            supplierId: $order->partner_id,
            orderDate: $date,
            expectedDeliveryDate: $order->expected_return_date?->toDateString() ?? now()->addDays(30)->toDateString(),
            lines: [[
                'description' => 'Lavorazione c/terzi '.$order->order_number.' – '.$productName,
                'quantity_ordered' => (float) $order->planned_quantity,
                'unit_price' => (float) $order->unit_service_cost,
                'uom' => 'PCS',
                'tax_rate' => 0,
                'expected_delivery_date' => $order->expected_return_date?->toDateString() ?? now()->addDays(30)->toDateString(),
            ]],
            orderType: 'subcontract',
            currency: $order->currency,
            notes: 'Conto lavoro passivo '.$order->order_number,
            tenantId: (string) $order->tenant_id,
        ));
    }
}
