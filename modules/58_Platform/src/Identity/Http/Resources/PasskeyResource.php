<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Platform\Identity\Infrastructure\Models\WebAuthnCredential;

/**
 * A passkey or security key of the signed-in user, without any key material.
 *
 * @mixin WebAuthnCredential
 */
final class PasskeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'aaguid' => $this->aaguid,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
