<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A remembered device of the signed-in user. The token (and its hash) is never part of it.
 *
 * @mixin TrustedDevice
 */
final class TrustedDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'ip_address' => $this->ip_address,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
