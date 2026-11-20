<?php

declare(strict_types=1);

namespace Modules\Academy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Academy\Models\UserAcademyProgress;

/**
 * @mixin UserAcademyProgress
 */
final class MissionProgressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'mission_id' => (string) $this->mission_id,
            'track_id' => (string) $this->track_id,
            'status' => $this->status,
            'completed' => $this->status === UserAcademyProgress::Completed,
            'xp_awarded' => $this->xp_awarded,
            'badge_code' => $this->badge_code,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
