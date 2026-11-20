<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationAuditLog;

/**
 * @mixin PreservationAuditLog
 */
final class PreservationAuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'action' => $this->action,
            'preservation_batch_id' => $this->preservation_batch_id,
            'preservation_item_id' => $this->preservation_item_id,
            'actor_id' => $this->actor_id,
            'details' => $this->details_json ?? (object) [],
            'sha256_snapshot' => $this->sha256_snapshot,
            'previous_log_sha256' => $this->previous_log_sha256,
            'entry_sha256' => $this->entry_sha256,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
