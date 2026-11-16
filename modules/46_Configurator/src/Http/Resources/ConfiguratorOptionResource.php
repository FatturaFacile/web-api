<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Configurator\Models\ConfiguratorOption;

/**
 * An option of a group.
 *
 * @mixin ConfiguratorOption
 */
final class ConfiguratorOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'price_delta' => $this->price_delta,
            'price_multiplier' => $this->price_multiplier,
            'cost' => $this->cost,
            'product_id' => $this->product_id,
            'price_from_product' => $this->price_from_product,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
