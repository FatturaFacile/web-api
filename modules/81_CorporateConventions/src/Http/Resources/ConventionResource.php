<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CorporateConventions\Models\ConventionDiscountRule;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;

/**
 * @mixin CorporatePartnerConvention
 */
final class ConventionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'slug' => $this->slug,
            'landing_path' => '/convenzioni/'.$this->slug,
            'name' => $this->name,
            'partner_company_name' => $this->partner_company_name,
            'partner_vat_number' => $this->partner_vat_number,
            'partner_uid_number' => $this->partner_uid_number,
            'partner_contact_id' => $this->partner_contact_id,
            'partner_type' => $this->partner_type->value,
            'country_code' => $this->country_code,
            'currency' => $this->currency,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'status' => $this->status->value,
            'eligible_population' => $this->eligible_population,
            'hr_contact_name' => $this->hr_contact_name,
            'hr_contact_email' => $this->hr_contact_email,
            'hr_contact_phone' => $this->hr_contact_phone,
            'has_contract_document' => $this->contract_document_path !== null,
            'verification_methods' => $this->verification_methods ?? [],
            'allowed_email_domains' => $this->allowed_email_domains ?? [],
            'card_number_prefix' => $this->card_number_prefix,
            'card_number_regex' => $this->card_number_regex,
            'static_convention_code' => $this->static_convention_code,
            'allow_family_members' => $this->allow_family_members,
            'max_family_members' => $this->max_family_members,
            'annual_spending_cap_per_beneficiary' => $this->annual_spending_cap_per_beneficiary,
            'employer_subsidy_enabled' => $this->employer_subsidy_enabled,
            'employer_subsidy_type' => $this->employer_subsidy_type?->value,
            'employer_subsidy_value' => $this->employer_subsidy_value,
            'employer_subsidy_annual_cap' => $this->employer_subsidy_annual_cap,
            'subsidy_purpose' => $this->subsidy_purpose->value,
            'split_billing_anonymized' => $this->split_billing_anonymized,
            'billing_cycle' => $this->billing_cycle->value,
            'has_logo' => $this->logo_path !== null,
            'primary_color' => $this->primary_color,
            'welcome_message_key' => $this->welcome_message_key,
            'enrolled_employees_count' => $this->whenCounted('enrolled_employees_count'),
            'enrolled_family_count' => $this->whenCounted('enrolled_family_count'),
            'discount_rules' => $this->whenLoaded('discountRules', fn (): array => $this->discountRules->map(static fn (ConventionDiscountRule $rule): array => [
                'id' => $rule->id,
                'priority' => $rule->priority,
                'rule_type' => $rule->rule_type->value,
                'target_price_list_id' => $rule->target_price_list_id,
                'target_category_id' => $rule->target_category_id,
                'discount_percentage' => $rule->discount_percentage,
                'exclude_promotional_items' => $rule->exclude_promotional_items,
                'cumulative_with_other_discounts' => $rule->cumulative_with_other_discounts,
                'min_cart_amount' => $rule->min_cart_amount,
            ])->values()->all()),
        ];
    }
}
