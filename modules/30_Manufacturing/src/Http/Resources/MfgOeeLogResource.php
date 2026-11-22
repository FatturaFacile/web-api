<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgOeeLog;

/**
 * A production execution log of a work centre or machine.
 *
 * @mixin MfgOeeLog
 */
final class MfgOeeLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_center_id' => $this->work_center_id,
            'machine_center_id' => $this->machine_center_id,
            'source' => $this->source,
            'period_start' => $this->period_start->toIso8601String(),
            'period_end' => $this->period_end->toIso8601String(),
            'planned_downtime_minutes' => $this->planned_downtime_minutes,
            'unplanned_downtime_minutes' => $this->unplanned_downtime_minutes,
            'loss_category' => $this->loss_category,
            'good_units' => $this->good_units,
            'scrap_units' => $this->scrap_units,
            'rework_units' => $this->rework_units,
            'notes' => $this->notes,
        ];
    }
}
