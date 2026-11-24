<?php

declare(strict_types=1);

namespace App\Http\Resources\Access;

use App\Models\Access\AccessTeam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccessTeam
 */
final class AccessTeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'custom_role_id' => $this->custom_role_id,
            'member_ids' => $this->getAttribute('member_ids') ?? [],
        ];
    }
}
