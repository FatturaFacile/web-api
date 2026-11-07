<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TransmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'network' => $this->network,
            'document_reference' => $this->document_reference,
            'recipient_identifier' => $this->recipient_identifier,
            'payload_sha256' => $this->payload_sha256,
            'status' => $this->status,
            'external_id' => $this->external_id,
            'driver' => $this->driver,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
