<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pricing record as its stored attributes, without the soft delete marker.
 */
final class PricingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->toArray();
        unset($attributes['deleted_at']);

        return $attributes;
    }
}
