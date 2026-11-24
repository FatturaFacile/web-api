<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Visitors\Models\VisitorVisit;

/**
 * A visit with its visitor and host.
 *
 * @mixin VisitorVisit
 */
final class VisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'visitor_id' => $this->visitor_id,
            'visitor' => new VisitorResource($this->whenLoaded('visitor')),
            'host_user_id' => $this->host_user_id,
            'host' => $this->whenLoaded('host', fn (): ?array => $this->host === null ? null : ['id' => $this->host->id, 'name' => $this->host->name]),
            'expected_at' => $this->expected_at?->toIso8601String(),
            'expected_until' => $this->expected_until?->toIso8601String(),
            'purpose' => $this->purpose,
            'status' => $this->status,
            'badge_number' => $this->badge_number,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
            'kiosk_id' => $this->kiosk_id,
            'host_notified_at' => $this->host_notified_at?->toIso8601String(),
        ];
    }
}
