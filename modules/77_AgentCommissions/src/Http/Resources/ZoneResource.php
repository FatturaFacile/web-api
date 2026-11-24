<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AgentCommissions\Models\Zone;

/**
 * @mixin Zone
 */
final class ZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'country' => $this->country,
            'regions' => $this->regions ?? [],
            'is_active' => (bool) $this->is_active,
        ];
    }
}
