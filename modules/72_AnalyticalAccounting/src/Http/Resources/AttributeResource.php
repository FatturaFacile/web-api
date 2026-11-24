<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base of the analytical resources: exposes exactly the attributes listed by the concrete resource, never the whole model.
 *
 * @property Model $resource
 */
abstract class AttributeResource extends JsonResource
{
    /**
     * @return list<string>
     */
    abstract protected function fields(): array;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $out = [];
        foreach ($this->fields() as $field) {
            $out[$field] = $this->resource->getAttribute($field);
        }

        return $out + $this->extra();
    }

    /**
     * @return array<string, mixed>
     */
    protected function extra(): array
    {
        return [];
    }
}
