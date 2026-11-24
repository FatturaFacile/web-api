<?php

declare(strict_types=1);

namespace App\Http\Resources\Access;

use App\Models\Access\TenantCustomRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantCustomRole
 */
final class CustomRoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'base_role' => $this->base_role,
            'modules' => (object) $this->modules,
            'members' => (int) ($this->getAttribute('members') ?? 0),
        ];
    }
}
