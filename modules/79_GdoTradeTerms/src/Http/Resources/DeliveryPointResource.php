<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GdoTradeTerms\Models\GdoDeliveryPoint;

/**
 * A store, Ce.Di. or head office of a retailer.
 *
 * @mixin GdoDeliveryPoint
 */
final class DeliveryPointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'code' => $this->code,
            'name' => $this->name,
            'point_type' => $this->point_type->value,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'is_active' => $this->is_active,
        ];
    }
}
