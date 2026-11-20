<?php

declare(strict_types=1);

namespace Modules\Copilot\Http\Resources;

use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of a Copilot entry of the audit ledger.
 *
 * @mixin AuditLedgerEntry
 */
final class CopilotAuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'action' => $this->action,
            'actor_user_id' => $this->actor_user_id,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'details' => is_string($this->payload_after) ? json_decode($this->payload_after, true) : $this->payload_after,
            'event_timestamp' => $this->event_timestamp->toIso8601String(),
        ];
    }
}
