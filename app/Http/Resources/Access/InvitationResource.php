<?php

declare(strict_types=1);

namespace App\Http\Resources\Access;

use App\Models\Access\TenantInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantInvitation
 */
final class InvitationResource extends JsonResource
{
    /**
     * The token hash never leaves the API.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'custom_role_id' => $this->custom_role_id,
            'modules' => $this->modules === null ? null : (object) $this->modules,
            'team_ids' => $this->team_ids ?? [],
            'status' => $this->effectiveStatus(),
            'invited_by' => $this->inviter?->name,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'last_sent_at' => $this->last_sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
