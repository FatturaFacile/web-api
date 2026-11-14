<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Governance\Models\GovControl;
use Modules\Governance\Services\GovernanceMembers;

/**
 * A control of the library with the state of its tests and the risks it mitigates (count, ids on the single record).
 *
 * @mixin GovControl
 */
final class ControlResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'control_type' => $this->control_type,
            'frequency' => $this->frequency,
            'framework_reference' => $this->framework_reference,
            'owner_id' => $this->owner_id,
            'owner' => app(GovernanceMembers::class)->reference($this->owner_id),
            'status' => $this->status,
            'last_tested_at' => $this->last_tested_at?->toDateString(),
            'last_test_result' => $this->last_test_result,
            'next_test_due_date' => $this->next_test_due_date?->toDateString(),
            'is_test_overdue' => $this->isTestOverdue(),
            'risk_ids' => $this->whenLoaded('risks', fn () => $this->risks->pluck('id')->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
