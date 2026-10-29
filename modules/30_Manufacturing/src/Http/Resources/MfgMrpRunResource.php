<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgMrpRun;

/**
 * @mixin MfgMrpRun
 */
final class MfgMrpRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_number' => $this->run_number,
            'run_type' => $this->run_type,
            'status' => $this->status,
            'horizon_start_date' => $this->horizon_start_date?->toDateString(),
            'horizon_end_date' => $this->horizon_end_date?->toDateString(),
            'total_items_processed' => $this->total_items_processed,
            'total_planned_orders' => $this->total_planned_orders,
            'total_shortages' => $this->total_shortages,
            'executed_at' => $this->executed_at?->toIso8601String(),
        ];
    }
}
