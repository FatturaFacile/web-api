<?php

declare(strict_types=1);

namespace Modules\Forecasting\Infrastructure\Models;

use DateTimeImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\Forecasting\Domain\Entities\SkuDemandForecast;

/**
 * Persisted SKU demand forecast.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $warehouse_id
 * @property Carbon $forecast_period_start
 * @property Carbon $forecast_period_end
 * @property string $predicted_demand_qty
 * @property string $confidence_lower_bound
 * @property string $confidence_upper_bound
 * @property string $recommended_reorder_point
 * @property string $recommended_safety_stock
 * @property string $seasonality_index
 * @property string $supplier_lead_time_avg_days
 * @property string $supplier_lead_time_std_dev_days
 * @property string $model
 * @property Carbon|null $applied_to_wms_at
 */
final class SkuDemandForecastRecord extends BaseEnterpriseModel
{
    protected $table = 'sku_demand_forecasts';

    protected function casts(): array
    {
        return [
            'forecast_period_start' => 'date',
            'forecast_period_end' => 'date',
            'predicted_demand_qty' => 'decimal:4',
            'confidence_lower_bound' => 'decimal:4',
            'confidence_upper_bound' => 'decimal:4',
            'recommended_reorder_point' => 'decimal:4',
            'recommended_safety_stock' => 'decimal:4',
            'seasonality_index' => 'decimal:4',
            'supplier_lead_time_avg_days' => 'decimal:2',
            'supplier_lead_time_std_dev_days' => 'decimal:2',
            'applied_to_wms_at' => 'datetime',
        ];
    }

    public function toDomain(): SkuDemandForecast
    {
        return new SkuDemandForecast(
            $this->id,
            $this->tenant_id,
            $this->product_id,
            $this->warehouse_id,
            DateTimeImmutable::createFromInterface($this->forecast_period_start),
            DateTimeImmutable::createFromInterface($this->forecast_period_end),
            (float) $this->predicted_demand_qty,
            (float) $this->confidence_lower_bound,
            (float) $this->confidence_upper_bound,
            (float) $this->recommended_reorder_point,
            (float) $this->recommended_safety_stock,
            (float) $this->seasonality_index,
            (float) $this->supplier_lead_time_avg_days,
            (float) $this->supplier_lead_time_std_dev_days,
            $this->applied_to_wms_at !== null ? DateTimeImmutable::createFromInterface($this->applied_to_wms_at) : null,
        );
    }
}
