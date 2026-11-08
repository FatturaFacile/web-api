<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectAuditEntry;

/**
 * API representation of an entry of the audit trail of a project (schema ProjectsAuditEntry).
 *
 * @mixin ProjectAuditEntry
 */
final class ProjectAuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectAuditEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'actor_user_id' => $entry->actor_user_id,
            'event_type' => $entry->event_type,
            'description' => $entry->description,
            'before_state' => $entry->before_state,
            'after_state' => $entry->after_state,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
