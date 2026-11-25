<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\SellOutReport;

/**
 * @mixin SellOutReport
 */
final class SellOutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'period' => $this->period,
            'gross_sales' => $this->gross_sales,
            'returns' => $this->returns,
            'discounts' => $this->discounts,
            'net_sales' => $this->net_sales,
            'tickets_count' => $this->tickets_count,
            'visitors_count' => $this->visitors_count,
            'cost_of_goods_sold' => $this->cost_of_goods_sold,
            'average_inventory_value' => $this->average_inventory_value,
            'source' => $this->source->value,
            'pos_reference' => $this->pos_reference,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
        ];
    }
}
