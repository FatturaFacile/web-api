<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BusinessIntelligence\Models\BiDashboardWidget;

/**
 * A widget with its place on the grid.
 *
 * @mixin BiDashboardWidget
 */
final class WidgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dashboard_id' => $this->dashboard_id,
            'saved_query_id' => $this->saved_query_id,
            'title' => $this->title,
            'widget_type' => $this->widget_type,
            'dataset' => $this->dataset,
            'spec' => $this->spec,
            'calculated_measures' => $this->calculated_measures ?? [],
            'config' => $this->config ?? (object) [],
            'layout' => $this->rectangle(),
        ];
    }
}
