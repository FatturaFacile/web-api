<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ResourcePlanning\Infrastructure\Models\UtilizationTarget;

/**
 * @mixin UtilizationTarget
 */
final class UtilizationTargetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'department_id' => $this->department_id,
            'cost_center_id' => $this->cost_center_id,
            'target_percent' => (float) $this->target_percent,
        ];
    }
}
