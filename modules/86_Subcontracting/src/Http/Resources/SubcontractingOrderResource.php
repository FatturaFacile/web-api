<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Subcontracting\Models\SubcontractingOrder;
use Modules\Subcontracting\Models\SubcontractingOrderComponent;

/**
 * @mixin SubcontractingOrder
 */
final class SubcontractingOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $products = Product::query()
            ->whereIn('id', array_filter([$this->output_product_id, ...($this->relationLoaded('components') ? $this->components->pluck('component_product_id')->all() : [])]))
            ->get(['id', 'sku', 'name'])
            ->keyBy('id');

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'direction' => $this->direction->value,
            'subcontracting_type' => $this->subcontracting_type->value,
            'status' => $this->status->value,
            'partner_type' => $this->partner_type,
            'partner_id' => $this->partner_id,
            'partner_name' => $this->partner_name,
            'source_warehouse_id' => $this->source_warehouse_id,
            'subcontractor_warehouse_id' => $this->subcontractor_warehouse_id,
            'bom_id' => $this->bom_id,
            'linked_production_order_id' => $this->linked_production_order_id,
            'linked_production_order_operation_id' => $this->linked_production_order_operation_id,
            'routing_operation_label' => $this->routing_operation_label,
            'linked_service_purchase_order_id' => $this->linked_service_purchase_order_id,
            'linked_customer_sales_order_id' => $this->linked_customer_sales_order_id,
            'outgoing_material_ddt_id' => $this->outgoing_material_ddt_id,
            'inbound_return_document_type' => $this->inbound_return_document_type,
            'inbound_return_document_id' => $this->inbound_return_document_id,
            'inbound_return_document_number' => $this->inbound_return_document_number,
            'output_product_id' => $this->output_product_id,
            'output_product_sku' => $products[$this->output_product_id]->sku ?? null,
            'output_product_name' => $products[$this->output_product_id]->name ?? null,
            'planned_quantity' => $this->planned_quantity,
            'received_quantity' => $this->received_quantity,
            'scrap_quantity' => $this->scrap_quantity,
            'delivered_quantity' => $this->delivered_quantity,
            'open_quantity' => $this->openQuantity(),
            'unit_service_cost' => $this->unit_service_cost,
            'currency' => $this->currency,
            'expected_return_date' => $this->expected_return_date?->toDateString(),
            'customs_regime' => $this->customs_regime->value,
            'customs_declaration_id' => $this->customs_declaration_id,
            'capitalized_service_amount' => $this->capitalized_service_amount,
            'capitalized_unit_cost' => $this->capitalized_unit_cost,
            'service_billing' => $this->service_billing,
            'zero_balance_sheet_value' => $this->direction->isCustomerOwned(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'components' => $this->whenLoaded('components', fn (): array => $this->components->map(static fn (SubcontractingOrderComponent $c): array => [
                'id' => $c->id,
                'component_product_id' => $c->component_product_id,
                'sku' => $products[$c->component_product_id]->sku ?? null,
                'product_name' => $products[$c->component_product_id]->name ?? null,
                'unit_of_measure' => $c->unit_of_measure,
                'required_qty_per_unit' => $c->required_qty_per_unit,
                'total_planned_qty' => $c->total_planned_qty,
                'dispatched_qty' => $c->dispatched_qty,
                'consumed_qty' => $c->consumed_qty,
                'scrap_qty' => $c->scrap_qty,
                'returned_unused_qty' => $c->returned_unused_qty,
                'held_qty' => $c->heldQuantity(),
                'to_dispatch_qty' => $c->toDispatchQuantity(),
                'lot_id' => $c->lot_id,
                'serial_number' => $c->serial_number,
            ])->all()),
        ];
    }
}
