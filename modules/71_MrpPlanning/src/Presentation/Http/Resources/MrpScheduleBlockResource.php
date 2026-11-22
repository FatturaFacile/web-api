<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\MrpPlanning\Infrastructure\Models\MrpScheduleBlock;

/**
 * @property MrpScheduleBlock $resource
 */
final class MrpScheduleBlockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $block = $this->resource;

        return [
            'id' => $block->id,
            'mrp_run_id' => $block->mrp_run_id,
            'proposal_id' => $block->proposal_id,
            'work_center_id' => $block->work_center_id,
            'original_work_center_id' => $block->original_work_center_id,
            'operation_sequence' => $block->operation_sequence,
            'start_date' => $block->start_date->toDateString(),
            'end_date' => $block->end_date->toDateString(),
            'minutes' => $block->minutes,
            'segments' => $block->segments ?? [],
            'is_manual' => $block->is_manual,
        ];
    }
}
