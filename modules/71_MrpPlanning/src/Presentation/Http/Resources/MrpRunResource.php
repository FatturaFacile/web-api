<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\MrpPlanning\Infrastructure\Models\MrpRunExecution;

/**
 * @property MrpRunExecution $resource
 */
final class MrpRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $run = $this->resource;
        $metrics = $run->summary_metrics ?? [];
        unset($metrics['item_signatures']);

        return [
            'id' => $run->id,
            'run_code' => $run->run_code,
            'status' => $run->status->value,
            'run_mode' => $run->run_mode->value,
            'planning_horizon_days' => $run->planning_horizon_days,
            'capacity_scheduling_mode' => $run->capacity_scheduling_mode->value,
            'scheduling_direction' => $run->scheduling_direction->value,
            'include_cross_border_customs_lead_time' => $run->include_cross_border_customs_lead_time,
            'apply_supplier_shutdown_calendars' => $run->apply_supplier_shutdown_calendars,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'execution_duration_ms' => $run->execution_duration_ms,
            'summary_metrics' => $metrics,
            'error_message' => $run->error_message,
        ];
    }
}
