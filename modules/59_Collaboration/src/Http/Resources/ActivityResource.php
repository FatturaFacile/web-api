<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Resources;

use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An entry of the audit ledger of a record. Values are not exposed, only which fields changed.
 *
 * @mixin AuditLedgerEntry
 */
final class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $diff = $this->diff_json;
        $changed = is_array($diff) ? array_keys($diff) : [];

        return [
            'id' => $this->id,
            'action' => $this->action,
            'module_code' => $this->module_code,
            'actor' => $this->actor_user_id === null ? null : ['id' => $this->actor_user_id, 'name' => $this->user?->name],
            'changed_fields' => array_map('strval', $changed),
            'occurred_at' => $this->event_timestamp->toIso8601String(),
        ];
    }
}
