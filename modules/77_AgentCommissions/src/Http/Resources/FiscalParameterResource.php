<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AgentCommissions\Models\FiscalParameter;

/**
 * @mixin FiscalParameter
 */
final class FiscalParameterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'mandate_type' => $this->mandate_type,
            'year' => $this->year,
            'value' => $this->value === null ? null : (string) $this->value,
            'payload' => $this->payload,
            'label_key' => $this->label_key,
            'is_override' => $this->tenant_id !== null,
        ];
    }
}
