<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactConsentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'purpose' => $this->purpose,
            'regime' => $this->regime,
            'granted' => (bool) $this->granted,
            'granted_at' => $this->granted_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'source' => $this->source,
            'notes' => $this->notes,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
