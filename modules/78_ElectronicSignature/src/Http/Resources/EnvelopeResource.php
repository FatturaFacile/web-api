<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ElectronicSignature\Models\SignatureEnvelope;

/**
 * @mixin SignatureEnvelope
 */
final class EnvelopeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'signable_type' => $this->signable_type,
            'signable_id' => $this->signable_id,
            'verification_code' => $this->verification_code,
            'title' => $this->title,
            'subject' => $this->subject,
            'message' => $this->message,
            'required_level' => $this->required_level->value,
            'jurisdiction' => $this->jurisdiction->value,
            'routing_mode' => $this->routing_mode->value,
            'status' => $this->status->value,
            'provider' => $this->provider->value,
            'provider_config_id' => $this->provider_config_id,
            'locale' => $this->locale,
            'document_format' => $this->document_format,
            'file_name' => $this->file_name,
            'original_sha256' => $this->original_sha256,
            'signed_sha256' => $this->signed_sha256,
            'has_signed_document' => $this->signed_path !== null,
            'sandbox' => (bool) ($this->metadata['sandbox'] ?? false),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'recipients' => RecipientResource::collection($this->whenLoaded('recipients')),
        ];
    }
}
