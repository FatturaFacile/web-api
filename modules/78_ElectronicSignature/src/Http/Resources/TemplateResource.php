<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ElectronicSignature\Models\SignatureTemplate;

/**
 * @mixin SignatureTemplate
 */
final class TemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'document_type' => $this->document_type,
            'required_level' => $this->required_level->value,
            'jurisdiction' => $this->jurisdiction->value,
            'provider_config_id' => $this->provider_config_id,
            'routing_mode' => $this->routing_mode->value,
            'expiration_days' => $this->expiration_days,
            'reminder_days' => $this->reminder_days ?? [],
            'min_amount' => $this->min_amount,
            'amount_currency' => $this->amount_currency,
            'is_active' => $this->is_active,
        ];
    }
}
