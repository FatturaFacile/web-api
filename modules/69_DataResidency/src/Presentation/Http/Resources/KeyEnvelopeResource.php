<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;

/**
 * The envelope without any secret material: neither the wrapped DEK nor the KMS credentials leave the server.
 *
 * @mixin CustomerManagedKeyEnvelope
 */
final class KeyEnvelopeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_entity_id' => $this->legal_entity_id,
            'mode' => $this->key_management_mode->value,
            'provider_type' => $this->provider_type->value,
            'external_key_uri' => $this->external_key_uri,
            'current_key_version' => $this->current_key_version,
            'status' => $this->status->value,
            'dek_cache_ttl_seconds' => $this->dek_cache_ttl_seconds,
            'rotation_interval_days' => $this->rotation_interval_days,
            'last_rotated_at' => $this->last_rotated_at?->toIso8601String(),
            'next_rotation_due_at' => $this->next_rotation_due_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revocation_reason' => $this->revocation_reason,
        ];
    }
}
