<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\KpiDashboards\Domain\Enums\ComparisonMode;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\Enums\WidgetType;

/**
 * @property string $id
 * @property string $dashboard_id
 * @property KpiCode $kpi_code
 * @property WidgetType $widget_type
 * @property string $data_source_query_name
 * @property ComparisonMode $default_time_comparison
 * @property array<string, mixed>|null $threshold_rules_json
 * @property string|null $drill_down_route_template
 * @property int $sort_order
 * @property int $grid_x
 * @property int $grid_y
 * @property int $grid_w
 * @property int $grid_h
 */
final class KpiWidget extends BaseEnterpriseModel
{
    protected $table = 'kpi_widgets';

    protected function casts(): array
    {
        return [
            'kpi_code' => KpiCode::class,
            'widget_type' => WidgetType::class,
            'default_time_comparison' => ComparisonMode::class,
            'threshold_rules_json' => 'array',
            'sort_order' => 'integer',
        ];
    }
}
