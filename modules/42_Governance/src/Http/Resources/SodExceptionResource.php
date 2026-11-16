<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Governance\Models\GovSodException;
use Modules\Governance\Services\GovernanceMembers;

/**
 * An exception to a segregation of duties rule, with its approver and expiry.
 *
 * @mixin GovSodException
 */
final class SodExceptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rule_id' => $this->rule_id,
            'rule_code' => $this->whenLoaded('rule', fn () => $this->rule->code),
            'user_id' => $this->user_id,
            'user' => app(GovernanceMembers::class)->reference($this->user_id),
            'justification' => $this->justification,
            'approved_by' => $this->approved_by,
            'approver' => app(GovernanceMembers::class)->reference($this->approved_by),
            'expires_at' => $this->expires_at?->toDateString(),
            'status' => $this->isActive() ? 'active' : ($this->status === 'revoked' ? 'revoked' : 'expired'),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
