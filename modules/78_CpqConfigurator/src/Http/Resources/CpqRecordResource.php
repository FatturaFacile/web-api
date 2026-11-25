<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqModel;
use Modules\CpqConfigurator\Support\Localized;

/**
 * Any record of the configurator definition: the stored columns (the {it, en, de, fr} maps as they are, for the editors),
 * a `display` text in the language of the request, and the children that were loaded (options of a characteristic,
 * characteristics, rules, mappings and formulas of a model).
 *
 * @mixin Model
 */
final class CpqRecordResource extends JsonResource
{
    /** @var list<string> */
    private const array TextColumns = ['label', 'name', 'item_name', 'operation_name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = array_diff_key($this->resource->toArray(), array_flip(['tenant_id', 'deleted_at', 'characteristics', 'options', 'rules', 'bom_mappings', 'routing_mappings', 'pricing_formulas']));

        foreach (self::TextColumns as $column) {
            if (array_key_exists($column, $data)) {
                $data['display'] = Localized::pick($data[$column]);

                break;
            }
        }

        if ($this->resource instanceof CpqModel && $this->resource->relationLoaded('characteristics')) {
            $data['characteristics'] = self::collection($this->resource->characteristics);
            $data['rules'] = self::collection($this->resource->rules);
            $data['bom_mappings'] = self::collection($this->resource->bomMappings);
            $data['routing_mappings'] = self::collection($this->resource->routingMappings);
            $data['pricing_formulas'] = self::collection($this->resource->pricingFormulas);
        }

        if ($this->resource instanceof CpqCharacteristic && $this->resource->relationLoaded('options')) {
            $data['options'] = self::collection($this->resource->options);
        }

        return $data;
    }
}
