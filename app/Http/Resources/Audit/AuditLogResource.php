<?php

declare(strict_types=1);

namespace App\Http\Resources\Audit;

use App\Models\AuditLog;
use App\Services\Audit\AuditChainCryptoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Summary of an audit record for lists and timelines: no payload, only which fields changed.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $crypto = app(AuditChainCryptoService::class);

        return [
            'id' => $this->id,
            'sequence_number' => $this->sequence_number,
            'event_type' => $this->event_type,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'actor' => [
                'type' => $this->actor_type,
                'id' => $this->actor_id,
                'email' => $this->actor_email,
                'role' => $this->actor_role,
            ],
            'ip_address' => $this->ip_address,
            'route_name' => $this->route_name,
            'request_id' => $this->request_id,
            'changed_fields' => $this->changed_fields ?? [],
            'context_metadata' => $this->context_metadata,
            'occurred_at' => $crypto->normaliseTimestamp($this->occurred_at),
            'worm_sealed' => $this->relationLoaded('wormEntry') ? $this->wormEntry !== null : null,
            'has_payload' => $this->old_values_encrypted !== null || $this->new_values_encrypted !== null,
        ];
    }
}
