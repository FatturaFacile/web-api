<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ServiceReportLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceReportLine
 */
class ServiceReportLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_report_id' => $this->service_report_id,
            'line_type_id' => $this->line_type_id,
            'line_type' => $this->whenLoaded('lineType', fn () => [
                'id' => $this->lineType->id,
                'code' => $this->lineType->code,
                'label' => $this->lineType->translation?->label ?? $this->lineType->code,
            ]),
            'item_id' => $this->item_id,
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->id,
                'code' => $this->item->code,
                'name' => $this->item->name,
            ]),
            'description' => $this->description,
            'quantity' => (float) $this->quantity,
            'unit_of_measure' => $this->unit_of_measure,
            'unit_price' => (float) $this->unit_price,
            'discount_percent' => (float) $this->discount_percent,
            'vat_code_id' => $this->vat_code_id,
            'tax_rate' => (float) $this->tax_rate,
            'line_net' => (float) $this->line_net,
            'line_tax' => (float) $this->line_tax,
            'line_total' => (float) $this->line_total,
            'is_billable' => $this->is_billable,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
