<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoOrder;

/**
 * @mixin GelatoOrder
 */
final class GelatoOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'order_number', 'kind', 'customer_name', 'customer_phone', 'components', 'total_price', 'component_cost', 'status', 'pickup_at', 'notes']);
    }
}
