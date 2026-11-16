<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Documents\Models\DmsApprovalTemplate;

/**
 * API representation of an approval template and its ordered steps.
 *
 * @mixin DmsApprovalTemplate
 */
final class ApprovalTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'document_type' => $this->document_type,
            'is_active' => $this->is_active,
            'steps' => $this->whenLoaded('steps', fn (): array => $this->steps->map(static fn ($step): array => [
                'position' => $step->position,
                'name' => $step->name,
                'approver_type' => $step->approver_type,
                'approver_user_id' => $step->approver_user_id,
                'approver_role' => $step->approver_role,
            ])->all()),
        ];
    }
}
