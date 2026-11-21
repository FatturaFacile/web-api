<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Platform\Identity\Infrastructure\Models\Role;

/** @mixin Role */
final class RoleResource extends JsonResource
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
            'is_system' => $this->is_system,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('permission')->values()),
            'abac_rules' => $this->whenLoaded('abacRules', fn () => $this->abacRules->map(fn ($r) => ['permission' => $r->permission, 'effect' => $r->effect, 'conditions' => $r->conditions])->values()),
            'field_policies' => $this->whenLoaded('fieldPolicies', fn () => $this->fieldPolicies->map(fn ($p) => ['resource' => $p->resource, 'field' => $p->field, 'visibility' => $p->visibility->value, 'kind' => $p->kind])->values()),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
