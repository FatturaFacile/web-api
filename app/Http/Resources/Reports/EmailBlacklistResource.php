<?php

declare(strict_types=1);

namespace App\Http\Resources\Reports;

use App\Models\EmailBlacklist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailBlacklist
 */
final class EmailBlacklistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'is_global' => $this->tenant_id === null,
            'email' => $this->email,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
