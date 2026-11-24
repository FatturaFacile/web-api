<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Configurator\Models\ConfigurableModel;

/**
 * A configurable model; its groups (with options) and rules come along when they are loaded.
 *
 * @mixin ConfigurableModel
 */
final class ConfigurableModelResource extends JsonResource
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
            'description' => $this->description,
            'base_product_id' => $this->base_product_id,
            'base_price_from_product' => $this->base_price_from_product,
            'base_price' => $this->base_price,
            'base_cost' => $this->base_cost,
            'currency' => $this->currency,
            'tax_rate' => $this->tax_rate,
            'min_margin_percent' => $this->min_margin_percent,
            'volume_breaks' => $this->volume_breaks ?? [],
            'status' => $this->status,
            'version' => $this->version,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'groups' => ConfiguratorOptionGroupResource::collection($this->whenLoaded('groups')),
            'rules' => ConfiguratorRuleResource::collection($this->whenLoaded('rules')),
        ];
    }
}
