<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\MarketingAutomation\Models\MarketingBlueprint;

/**
 * @mixin MarketingBlueprint
 */
final class BlueprintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'code' => $this->code,
            'texts' => $this->texts ?? new \stdClass,
            'payload' => $this->payload ?? new \stdClass,
        ];
    }
}
