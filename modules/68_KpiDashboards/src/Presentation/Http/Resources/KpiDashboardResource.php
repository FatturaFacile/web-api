<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\KpiDashboards\Infrastructure\Models\KpiDashboard;
use Modules\KpiDashboards\Infrastructure\Models\KpiWidget;

/**
 * A saved cockpit layout with its widgets.
 *
 * @mixin KpiDashboard
 */
final class KpiDashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role_code' => $this->role_code->value,
            'title_key' => $this->title_key,
            'custom_title' => $this->custom_title,
            'layout_grid' => $this->layout_grid_json,
            'refresh_interval_seconds' => $this->refresh_interval_seconds,
            'is_system_default' => $this->is_system_default,
            'version' => $this->version,
            'widgets' => $this->widgets->map(static fn (KpiWidget $widget): array => [
                'id' => $widget->id,
                'kpi_code' => $widget->kpi_code->value,
                'widget_type' => $widget->widget_type->value,
                'data_source_query_name' => $widget->data_source_query_name,
                'default_time_comparison' => $widget->default_time_comparison->value,
                'threshold_rules' => $widget->threshold_rules_json,
                'drill_down_route_template' => $widget->drill_down_route_template,
                'sort_order' => $widget->sort_order,
                'position' => ['x' => $widget->grid_x, 'y' => $widget->grid_y, 'w' => $widget->grid_w, 'h' => $widget->grid_h],
            ])->all(),
        ];
    }
}
