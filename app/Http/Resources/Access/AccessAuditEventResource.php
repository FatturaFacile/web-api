<?php

declare(strict_types=1);

namespace App\Http\Resources\Access;

use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * An ACCESS event of the audit ledger: `event` is `access.<type>.<what>` and `type` its first two segments.
 *
 * @mixin AuditLedgerEntry
 */
final class AccessAuditEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = strtolower((string) $this->action);

        return [
            'id' => $this->id,
            'event' => $event,
            'type' => implode('.', array_slice(explode('.', $event), 0, 2)),
            'subject_id' => $this->resource_id,
            'actor' => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email],
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'created_at' => Carbon::parse($this->event_timestamp)->toIso8601String(),
        ];
    }
}
