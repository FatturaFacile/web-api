<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Configurator\Models\ConfiguratorRule;

/**
 * A rule over the selected options.
 *
 * @mixin ConfiguratorRule
 */
final class ConfiguratorRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'model_id' => $this->model_id,
            'name' => $this->name,
            'type' => $this->type,
            'match' => $this->match,
            'conditions' => $this->conditions ?? [],
            'target_options' => $this->target_options ?? [],
            'price_effect_type' => $this->price_effect_type,
            'price_effect_value' => $this->price_effect_value,
            'message' => $this->message,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
        ];
    }
}
