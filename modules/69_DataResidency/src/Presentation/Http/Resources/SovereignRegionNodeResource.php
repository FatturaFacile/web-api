<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DataResidency\Infrastructure\Models\SovereignRegionNode;

/**
 * @mixin SovereignRegionNode
 */
final class SovereignRegionNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            ...$this->coordinates()->toArray(),
            'service_type' => $this->service_type->value,
            'is_active' => $this->is_active,
        ];
    }
}
