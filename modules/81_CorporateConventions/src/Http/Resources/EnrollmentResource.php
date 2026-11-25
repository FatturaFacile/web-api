<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;

/**
 * @mixin ConventionBeneficiaryEnrollment
 */
final class EnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $balance = $this->balanceFor((int) now()->year);
        $convention = $this->relationLoaded('convention') ? $this->convention : null;
        $cap = $convention?->annual_spending_cap_per_beneficiary;
        $subsidyCap = $convention?->employer_subsidy_annual_cap;

        return [
            'id' => $this->id,
            'convention_id' => $this->convention_id,
            'convention_name' => $convention?->name,
            'currency' => $convention?->currency,
            'customer_id' => $this->customer_id,
            'beneficiary_type' => $this->beneficiary_type->value,
            'parent_enrollment_id' => $this->parent_enrollment_id,
            'relationship' => $this->relationship->value,
            'full_name' => $this->full_name,
            'verified_email' => $this->verified_email,
            'membership_card_number' => $this->membership_card_number,
            'verification_method' => $this->verification_method->value,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'status' => $this->status->value,
            'wallet_pass_id' => $this->wallet_pass_id,
            'qr_payload' => $this->qr_payload,
            'annual_used_amount' => $balance->usedAmount,
            'annual_used_subsidy' => $balance->usedSubsidy,
            'annual_spending_cap' => $cap,
            'annual_cap_remaining' => $cap === null ? null : number_format(max(0.0, (float) $cap - (float) $balance->usedAmount), 2, '.', ''),
            'employer_subsidy_cap_remaining' => $subsidyCap === null ? null : number_format(max(0.0, (float) $subsidyCap - (float) $balance->usedSubsidy), 2, '.', ''),
            'family_members' => $this->whenLoaded('familyMembers', fn () => self::collection($this->familyMembers)),
        ];
    }
}
