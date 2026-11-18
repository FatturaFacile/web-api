<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Governance\Models\GovSodViolation;
use Modules\Governance\Services\GovernanceMembers;

/**
 * A violation of the register, with the rule and the member.
 *
 * @mixin GovSodViolation
 */
final class SodViolationResource extends JsonResource
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
            'rule_title' => $this->whenLoaded('rule', fn () => $this->rule->title),
            'severity' => $this->whenLoaded('rule', fn () => $this->rule->severity),
            'user_id' => $this->user_id,
            'user' => app(GovernanceMembers::class)->reference($this->user_id),
            'duty_a' => $this->duty_a,
            'duty_b' => $this->duty_b,
            'status' => $this->status,
            'exception_id' => $this->exception_id,
            'detected_at' => $this->detected_at->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
