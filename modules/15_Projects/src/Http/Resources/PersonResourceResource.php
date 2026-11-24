<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectResource as ProjectResourceModel;

/**
 * API representation of a person working on projects: default rates and weekly capacity (schema ProjectsPersonResource).
 *
 * @mixin ProjectResourceModel
 */
final class PersonResourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectResourceModel $resource */
        $resource = $this->resource;
        $profile = $resource->capacityProfile;

        return [
            'id' => $resource->id,
            'user_id' => $resource->user_id,
            'name' => $resource->name,
            'resource_type' => $resource->resource_type,
            'internal_hourly_cost' => (string) $resource->internal_hourly_cost,
            'billing_hourly_rate' => (string) $resource->billing_hourly_rate,
            'weekly_capacity_hours' => $profile !== null ? (string) $profile->weekly_capacity_hours : null,
            'is_active' => $resource->is_active,
        ];
    }
}
