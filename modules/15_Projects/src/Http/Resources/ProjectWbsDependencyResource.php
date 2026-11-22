<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectWbsDependency;

/**
 * API representation of a WBS dependency.
 *
 * @mixin ProjectWbsDependency
 */
final class ProjectWbsDependencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectWbsDependency $dependency */
        $dependency = $this->resource;

        return [
            'id' => $dependency->id,
            'project_id' => $dependency->project_id,
            'predecessor_node_id' => $dependency->predecessor_node_id,
            'successor_node_id' => $dependency->successor_node_id,
            'dependency_type' => $dependency->dependency_type,
            'lag_days' => $dependency->lag_days,
        ];
    }
}
