<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SkiOutdoorSchool\Models\PackageItem;

/**
 * @mixin PackageItem
 */
final class PackageItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'enrollment_id' => $this->enrollment_id,
            'item_type' => $this->item_type->value,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'days' => $this->days,
            'unit_price_net' => $this->unit_price_net,
            'vat_rate' => $this->vat_rate,
            'line_net' => $this->lineNet(),
            'product_id' => $this->product_id,
            'status' => $this->status->value,
        ];
    }
}
