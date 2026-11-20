<?php

declare(strict_types=1);

namespace Modules\Forecasting\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Forecasting\Infrastructure\Models\SkuDemandForecastRecord;

/**
 * @mixin SkuDemandForecastRecord
 */
final class SkuDemandForecastResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'warehouse_id' => $this->warehouse_id,
            'forecast_period_start' => $this->forecast_period_start->toDateString(),
            'forecast_period_end' => $this->forecast_period_end->toDateString(),
            'predicted_demand_qty' => (float) $this->predicted_demand_qty,
            'confidence_lower_bound' => (float) $this->confidence_lower_bound,
            'confidence_upper_bound' => (float) $this->confidence_upper_bound,
            'recommended_reorder_point' => (float) $this->recommended_reorder_point,
            'recommended_safety_stock' => (float) $this->recommended_safety_stock,
            'seasonality_index' => (float) $this->seasonality_index,
            'supplier_lead_time_avg_days' => (float) $this->supplier_lead_time_avg_days,
            'supplier_lead_time_std_dev_days' => (float) $this->supplier_lead_time_std_dev_days,
            'model' => $this->model,
            'applied_to_wms_at' => $this->applied_to_wms_at?->toIso8601String(),
        ];
    }
}
