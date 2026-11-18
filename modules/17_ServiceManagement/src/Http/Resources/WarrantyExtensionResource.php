<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\WarrantyExtension;

/**
 * @mixin WarrantyExtension
 */
final class WarrantyExtensionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'months' => $this->months,
            'starts_at' => $this->starts_at->toDateString(),
            'ends_at' => $this->ends_at->toDateString(),
            'reference' => $this->reference,
            'price' => $this->price,
            'currency' => $this->currency,
        ];
    }
}
