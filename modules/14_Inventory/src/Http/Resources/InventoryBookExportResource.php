<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\InventoryBookExport;
use Modules\Inventory\Support\Decimal;

/**
 * @mixin InventoryBookExport
 */
final class InventoryBookExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InventoryBookExport $export */
        $export = $this->resource;

        return [
            'id' => $export->id,
            'year' => $export->year,
            'as_of' => $export->as_of->toDateString(),
            'company_id' => $export->company_id,
            'warehouse_id' => $export->warehouse_id,
            'status' => $export->status,
            'rows_count' => $export->rows_count,
            'totals' => [
                'quantity' => Decimal::quantity((string) $export->total_quantity),
                'total_value' => Decimal::money((string) $export->total_value),
            ],
            'reconciliation' => [
                'gl_inventory_balance' => Decimal::money((string) $export->gl_inventory_balance),
                'difference' => Decimal::money((string) $export->difference),
            ],
            'method_breakdown' => $export->method_breakdown ?? [],
            'checksum' => $export->checksum,
            'generated_by' => $export->generated_by,
            'generated_at' => $export->generated_at->toIso8601String(),
        ];
    }
}
