<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\PassengerTransport\Models\SchoolBusContract;

/**
 * @mixin SchoolBusContract
 */
final class SchoolContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'contact_name' => $this->whenLoaded('contact', fn (): ?string => $this->contact?->company_name),
            'code' => $this->code,
            'name' => $this->name,
            'currency' => $this->currency,
            'rate_card' => $this->rate_card ?? [],
            'monthly_flat_amount' => $this->monthly_flat_amount,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'is_active' => $this->is_active,
        ];
    }
}
