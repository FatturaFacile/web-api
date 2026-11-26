<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Models\ScimGroupMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A SCIM group to role mapping. `member_count` is the number of members the group has now (null while the identity
 * provider has not pushed the group).
 *
 * @mixin ScimGroupMapping
 */
final class ScimGroupMappingResource extends JsonResource
{
    /**
     * @param  array<string, int>  $memberCounts  by lower-cased group name
     */
    public function __construct($resource, private readonly array $memberCounts = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_name' => $this->group_name,
            'role' => $this->role,
            'member_count' => $this->memberCounts[mb_strtolower($this->group_name)] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
