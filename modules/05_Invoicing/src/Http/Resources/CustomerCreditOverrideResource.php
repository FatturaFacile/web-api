<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\CustomerCreditOverride;

/**
 * @mixin CustomerCreditOverride
 */
final class CustomerCreditOverrideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'scope' => $this->scope,
            'reason' => $this->reason,
            'authorized_by' => $this->authorized_by,
            'expires_at' => $this->expires_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'revoke_reason' => $this->revoke_reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
