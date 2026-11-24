<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectWbsBaseline;

/**
 * API representation of a WBS baseline: identification and project-level figures; the node snapshot is read through the
 * variance endpoint.
 *
 * @mixin ProjectWbsBaseline
 */
final class ProjectWbsBaselineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectWbsBaseline $baseline */
        $baseline = $this->resource;

        return [
            'id' => $baseline->id,
            'project_id' => $baseline->project_id,
            'baseline_number' => $baseline->baseline_number,
            'name' => $baseline->name,
            'captured_at' => $baseline->captured_at->toIso8601String(),
            'captured_by' => $baseline->captured_by,
            'node_count' => count($baseline->snapshot_json['nodes'] ?? []),
            'project' => $baseline->snapshot_json['project'] ?? null,
        ];
    }
}
