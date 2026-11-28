<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DairyCheeseManufacturing\Models\CheeseAgingWheel;
use Modules\DairyCheeseManufacturing\Services\WheelMetrics;

/**
 * @mixin CheeseAgingWheel
 */
final class WheelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['batch', 'rack']);
        $m = app(WheelMetrics::class)->of($this->resource);

        return [
            'id' => $this->id, 'serial' => $this->serial, 'vat_batch_id' => $this->vat_batch_id, 'vat_batch_code' => $this->batch->code, 'cheese_type' => $this->batch->cheese_type,
            'rack_id' => $this->rack_id, 'room' => $this->rack?->room, 'rack' => $this->rack?->rack, 'shelf' => $this->rack?->shelf, 'position' => $this->position,
            'status' => $this->status, 'grade' => $this->grade, 'branded' => (bool) $this->branded, 'branded_at' => $this->branded_at?->toIso8601String(),
            'production_date' => $this->batch->production_date->toDateString(), 'age_months' => number_format($m['age_months'], 2, '.', ''),
            'initial_weight_kg' => $this->initial_weight_kg, 'current_weight_kg' => number_format($m['current_kg'], 3, '.', ''), 'theoretical_weight_kg' => number_format($m['theoretical_kg'], 3, '.', ''),
            'weight_loss_pct' => number_format($m['loss_pct'], 3, '.', ''), 'theoretical_weight_loss_pct' => number_format($m['theoretical_loss_pct'], 3, '.', ''),
            'sold_at' => $this->sold_at?->toIso8601String(), 'sold_weight_kg' => $this->sold_weight_kg, 'sale_variance_kg' => $this->sale_variance_kg,
            'customer_name' => $this->customer_name, 'customer_id' => $this->customer_id, 'invoice_ref' => $this->invoice_ref,
        ];
    }
}
