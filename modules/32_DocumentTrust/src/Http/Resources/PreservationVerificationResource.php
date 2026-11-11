<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DocumentTrust\Models\PreservationVerification;

/**
 * API representation of one integrity check.
 *
 * @mixin PreservationVerification
 */
final class PreservationVerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'package_id' => (string) $this->package_id,
            'result' => $this->result,
            'trigger' => $this->trigger,
            'manifest_ok' => (bool) $this->manifest_ok,
            'checked_items' => (int) $this->checked_items,
            'failed_items' => (int) $this->failed_items,
            'failures' => $this->failures ?? [],
            'verified_at' => $this->verified_at->toIso8601String(),
        ];
    }
}
