<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Distribution\Models\SalesChannel;

/**
 * @mixin SalesChannel
 */
final class SalesChannelResource extends JsonResource
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
            'channel_type' => $this->channel_type->value,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'description' => $this->description,
            'rules' => $this->whenLoaded('rules', fn () => SalesChannelWarehouseResource::collection($this->rules)),
        ];
    }
}
