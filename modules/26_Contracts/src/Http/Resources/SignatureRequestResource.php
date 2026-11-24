<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Contracts\Models\ContractSignatureRequest;

/**
 * An e-signature request with its signers and, when loaded, the audit trail. Signing tokens are never part of it.
 *
 * @mixin ContractSignatureRequest
 */
final class SignatureRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_kind' => $this->contract_kind,
            'contract_id' => $this->contract_id,
            'document_version_id' => $this->document_version_id,
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'status' => $this->status,
            'order_mode' => $this->order_mode,
            'document_hash' => $this->document_hash,
            'expires_on' => $this->expires_on?->toDateString(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'signers' => $this->whenLoaded('signers', fn (): array => $this->signers->map(static fn ($signer): array => ['id' => $signer->id, 'name' => $signer->name, 'email' => $signer->email, 'position' => $signer->position, 'status' => $signer->status, 'signed_at' => $signer->signed_at?->toIso8601String(), 'signature_hash' => $signer->signature_hash, 'decline_reason' => $signer->decline_reason])->all()),
            'events' => $this->whenLoaded('events', fn (): array => $this->events->map(static fn ($event): array => ['id' => $event->id, 'event' => $event->event, 'signer_id' => $event->signer_id, 'actor_id' => $event->actor_id, 'metadata' => $event->metadata, 'created_at' => $event->created_at?->toIso8601String()])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
