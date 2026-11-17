<?php

declare(strict_types=1);

namespace App\Http\Resources\Access;

use App\Models\Access\AccessDelegation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccessDelegation
 */
final class DelegationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delegator' => $this->party($this->delegator),
            'delegate' => $this->party($this->delegate),
            'role' => $this->role,
            'modules' => $this->modules,
            'reason' => $this->reason,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'status' => $this->status(),
        ];
    }

    /**
     * @return array{id: string, name: string, email: string}
     */
    private function party(?User $user): array
    {
        return ['id' => (string) $user?->id, 'name' => (string) $user?->name, 'email' => (string) $user?->email];
    }
}
