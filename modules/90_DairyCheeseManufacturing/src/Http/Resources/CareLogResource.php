<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DairyCheeseManufacturing\Models\AgingCareLog;
use Modules\DairyCheeseManufacturing\Services\CareService;

/**
 * @mixin AgingCareLog
 */
final class CareLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'care_type' => $this->care_type, 'status' => $this->status, 'scope' => $this->scope, 'target_id' => $this->target_id,
            'scheduled_for' => $this->scheduled_for->toDateString(), 'executed_at' => $this->executed_at?->toIso8601String(), 'wheels_affected' => (int) $this->wheels_affected,
            'performed_by' => $this->performed_by, 'notes' => $this->notes, 'is_overdue' => app(CareService::class)->isOverdue($this->resource),
        ];
    }
}
