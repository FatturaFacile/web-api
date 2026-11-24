<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TenantAccessRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantAccessRequest
 */
class TenantAccessRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'requested_company_name' => $this->requested_company_name,
            'requested_tax_id' => $this->requested_tax_id,
            'requested_country' => $this->requested_country,
            'tenant' => $this->tenant ? ['id' => $this->tenant->id, 'name' => $this->tenant->name] : null,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'email_verified_at' => $this->user->email_verified_at?->toIso8601String(),
            ],
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
