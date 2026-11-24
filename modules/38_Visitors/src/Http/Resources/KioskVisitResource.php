<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Visitors\Models\VisitorVisit;

/**
 * What a kiosk screen may show of a visit: first name and initial, the host name and the time. No contact data, no
 * company, no purpose.
 *
 * @mixin VisitorVisit
 */
final class KioskVisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'visitor_name' => $this->visitor?->kioskName(),
            'host_name' => $this->host?->name,
            'expected_at' => $this->expected_at?->toIso8601String(),
            'status' => $this->status,
            'badge_number' => $this->badge_number,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
        ];
    }
}
