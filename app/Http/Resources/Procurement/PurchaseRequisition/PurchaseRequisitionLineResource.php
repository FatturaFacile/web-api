<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\PurchaseRequisition;

use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseRequisitionLine
 */
final class PurchaseRequisitionLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PurchaseRequisitionLine $line */
        $line = $this->resource;

        return [
            'id' => (string) $line->id,
            'purchase_requisition_id' => (string) $line->purchase_requisition_id,
            'line_number' => (int) $line->line_number,
            'item_type' => (string) $line->item_type,
            'catalog_item_id' => $line->catalog_item_id,
            'supplier_catalog_id' => $line->supplier_catalog_id,
            'suggested_supplier_id' => $line->suggested_supplier_id,
            'description' => (string) $line->description,
            'quantity' => $line->effectiveQuantity(),
            'unit_of_measure' => $line->effectiveUnitOfMeasure(),
            'estimated_unit_price' => (string) $line->estimated_unit_price,
            'estimated_line_total' => $line->effectiveLineTotal(),
            'cost_center_id' => $line->cost_center_id,
            'project_id' => $line->project_id,
            'project' => $this->whenLoaded('project', fn (): ?array => $line->project !== null ? [
                'id' => (string) $line->project->id,
                'project_number' => (string) $line->project->project_number,
                'name' => (string) $line->project->name,
            ] : null),
            'converted_quantity' => (string) ($line->converted_quantity ?? '0.0000'),
            'remaining_convertible_quantity' => $line->remainingConvertibleQuantity(),
            'conversion_status' => (string) $line->conversion_status,
            'quantity_quoted' => (string) ($line->quantity_quoted ?? '0.0000'),
            'quantity_ordered' => (string) ($line->quantity_ordered ?? '0.0000'),
            'sourcing_status' => (string) $line->sourcing_status,
            'created_at' => $line->created_at?->toIso8601String(),
            'updated_at' => $line->updated_at?->toIso8601String(),
        ];
    }
}
