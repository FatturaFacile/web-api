<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\RmaLine;

/**
 * @mixin RmaLine
 */
final class RmaLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_type' => $this->line_type,
            'product_id' => $this->product_id,
            'description' => $this->description,
            'qty' => $this->qty,
            'unit_cost' => $this->unit_cost,
            'unit_price' => $this->unit_price,
            'charged_to' => $this->charged_to,
            'defective_part_serial' => $this->defective_part_serial,
            'supplier_id' => $this->supplier_id,
            'replacement_part_serial' => $this->replacement_part_serial,
        ];
    }
}
