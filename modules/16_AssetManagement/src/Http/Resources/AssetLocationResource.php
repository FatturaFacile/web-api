<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AssetLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'name' => $this->name,
            'location_type' => $this->location_type,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
