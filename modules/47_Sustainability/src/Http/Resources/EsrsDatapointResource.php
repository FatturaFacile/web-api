<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sustainability\Models\EsgEsrsDatapoint;

/**
 * An ESRS data point of the catalog.
 *
 * @mixin EsgEsrsDatapoint
 */
final class EsrsDatapointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'standard' => $this->standard,
            'disclosure' => $this->disclosure,
            'name' => $this->name,
            'description' => $this->description,
            'data_type' => $this->data_type,
            'unit' => $this->unit,
            'is_mandatory' => $this->is_mandatory,
            'auto_source' => $this->auto_source,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
