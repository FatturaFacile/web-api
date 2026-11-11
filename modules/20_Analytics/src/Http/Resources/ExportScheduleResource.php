<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Analytics\Models\ExportSchedule;

/**
 * API representation of a scheduled export.
 *
 * @mixin ExportSchedule
 */
final class ExportScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'designed_report_id' => $this->designed_report_id,
            'frequency' => $this->frequency,
            'day_of_week' => $this->day_of_week,
            'day_of_month' => $this->day_of_month,
            'format' => $this->format,
            'delivery' => $this->delivery,
            'recipients' => $this->recipients,
            'is_active' => $this->is_active,
            'next_run_on' => $this->next_run_on?->toDateString(),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'last_status' => $this->last_status,
            'last_error' => $this->last_error,
        ];
    }
}
