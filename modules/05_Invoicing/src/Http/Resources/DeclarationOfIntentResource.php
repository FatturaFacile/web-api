<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeclarationOfIntentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'contact_id' => (string) $this->contact_id,
            'direction' => $this->direction,
            'protocol_number' => $this->protocol_number,
            'receipt_date' => $this->receipt_date?->toDateString(),
            'year' => $this->year,
            'plafond_amount' => (string) $this->plafond_amount,
            'consumed_amount' => (string) $this->consumed_amount,
            'residual_amount' => $this->residualAmount(),
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
