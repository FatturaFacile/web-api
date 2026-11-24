<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workflow\Models\WorkflowAuditEntry;

/**
 * @mixin WorkflowAuditEntry
 */
final class WorkflowAuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_instance_id' => $this->workflow_instance_id,
            'workflow_instance_step_id' => $this->workflow_instance_step_id,
            'event_type' => $this->event_type,
            'event_payload' => $this->event_payload,
            'actor_user_id' => $this->actor_user_id,
            'hmac_signature' => $this->hmac_signature,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
