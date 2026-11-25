<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CorporateConventions\Domain\Enums\VerificationMethod;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;

/**
 * What the public landing page of a convention may show: branding, the way to verify and the family rule; never the discount
 * matrix, the subsidy, the employee list or the HR contact.
 *
 * @mixin CorporatePartnerConvention
 */
final class PublicConventionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $methods = $this->verification_methods ?? [];

        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'partner_company_name' => $this->partner_company_name,
            'country_code' => $this->country_code,
            'valid_to' => $this->valid_to?->toDateString(),
            'primary_color' => $this->primary_color,
            'welcome_message_key' => $this->welcome_message_key,
            'has_logo' => $this->logo_path !== null,
            'allow_family_members' => $this->allow_family_members,
            'max_family_members' => $this->max_family_members,
            'verification' => [
                'work_email_otp' => in_array(VerificationMethod::WorkEmailOtp->value, $methods, true),
                'identifier' => array_intersect([VerificationMethod::MembershipCardPrefix->value, VerificationMethod::HashedEmployeeList->value, VerificationMethod::PromoCode->value], $methods) !== [],
                'card' => in_array(VerificationMethod::MembershipCardPrefix->value, $methods, true),
                'hashed_list' => in_array(VerificationMethod::HashedEmployeeList->value, $methods, true),
                'promo_code' => in_array(VerificationMethod::PromoCode->value, $methods, true),
                'email_domains' => in_array(VerificationMethod::WorkEmailOtp->value, $methods, true) ? array_map(static fn (string $domain): string => '@'.ltrim($domain, '@'), $this->allowed_email_domains ?? []) : [],
            ],
        ];
    }
}
