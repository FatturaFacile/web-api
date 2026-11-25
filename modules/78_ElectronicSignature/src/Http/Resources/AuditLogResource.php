<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ElectronicSignature\Models\SignatureAuditLog;

/**
 * An entry of the audit trail. The event is a translation key suffix (`signatures.audit.<event>`).
 *
 * @mixin SignatureAuditLog
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'event' => $this->event,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'recipient_id' => $this->recipient_id,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'metadata' => $this->metadata,
            'document_sha256' => $this->document_sha256,
            'receipt' => $this->receipt,
            'has_timestamp_token' => $this->timestamp_token !== null,
            'previous_hash' => $this->previous_hash,
            'entry_hash' => $this->entry_hash,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
