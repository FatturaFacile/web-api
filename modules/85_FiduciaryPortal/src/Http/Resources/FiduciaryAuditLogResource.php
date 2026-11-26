<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FiduciaryAuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'tenant_id' => (string) $this->tenant_id,
            'actor_user_id' => $this->actor_user_id ? (string) $this->actor_user_id : null,
            'actor_name' => $this->actor_name,
            'actor_role' => $this->actor_role,
            'action' => (string) $this->action,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id ? (string) $this->target_id : null,
            'target_reference' => $this->target_reference,
            'summary' => (string) $this->summary,
            'metadata' => $this->metadata_json,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
