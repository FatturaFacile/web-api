<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\AssetAssignment;

/**
 * An assignment of an asset to an employee, department, project or site. Amounts are decimal strings.
 *
 * @mixin AssetAssignment
 */
final class AssetAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'assignment_number' => $this->assignment_number,
            'assignee_type' => $this->assignee_type,
            'assignee_id' => $this->assignee_id,
            'assignee_name' => $this->assignee_name,
            'department_id' => $this->department_id,
            'project_id' => $this->project_id,
            'location_id' => $this->location_id,
            'assigned_at' => $this->assigned_at?->format('Y-m-d'),
            'expected_return_at' => $this->expected_return_at?->format('Y-m-d'),
            'returned_at' => $this->returned_at?->format('Y-m-d'),
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
