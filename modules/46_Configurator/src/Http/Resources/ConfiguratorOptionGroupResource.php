<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Configurator\Models\ConfiguratorOptionGroup;

/**
 * An option group, with its options when they are loaded.
 *
 * @mixin ConfiguratorOptionGroup
 */
final class ConfiguratorOptionGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'model_id' => $this->model_id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'is_required' => $this->is_required,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'range_min' => $this->range_min,
            'range_max' => $this->range_max,
            'range_step' => $this->range_step,
            'default_value' => $this->default_value,
            'unit' => $this->unit,
            'unit_price_delta' => $this->unit_price_delta,
            'unit_cost' => $this->unit_cost,
            'product_id' => $this->product_id,
            'sort_order' => $this->sort_order,
            'options' => ConfiguratorOptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
