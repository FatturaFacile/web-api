<?php

declare(strict_types=1);

namespace Modules\DataExport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of a recurring export.
 */
final class ExportScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'dataset' => $this->dataset,
            'frequency' => $this->frequency,
            'period' => $this->period,
            'is_active' => (bool) $this->is_active,
            'next_run_on' => $this->next_run_on?->toDateString(),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
        ];
    }
}
