<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CorporateConventions\Domain\Enums\BillingCycle;
use Modules\CorporateConventions\Domain\Enums\PartnerType;
use Modules\CorporateConventions\Domain\Enums\RuleType;
use Modules\CorporateConventions\Domain\Enums\SubsidyPurpose;
use Modules\CorporateConventions\Domain\Enums\SubsidyType;
use Modules\CorporateConventions\Domain\Enums\VerificationMethod;
use Modules\CorporateConventions\Http\Requests\Concerns\ScopesToTenant;

final class SaveConventionRequest extends FormRequest
{
    use ScopesToTenant;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:40', $this->tenantUnique('sales_partner_conventions')->ignore($this->route('id'))],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'name' => [$required, 'string', 'max:160'],
            'partner_company_name' => [$required, 'string', 'max:160'],
            'partner_vat_number' => ['nullable', 'string', 'max:20', 'regex:/^(IT)?\d{11}$/'],
            'partner_uid_number' => ['nullable', 'string', 'max:20', 'regex:/^CHE-?\d{3}\.?\d{3}\.?\d{3}(\s?(MWST|TVA|IVA))?$/i'],
            'partner_type' => [$required, Rule::in(PartnerType::values())],
            'country_code' => [$required, Rule::in(['IT', 'CH'])],
            'currency' => [$required, Rule::in(['EUR', 'CHF'])],
            'valid_from' => [$required, 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'eligible_population' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'hr_contact_name' => ['nullable', 'string', 'max:160'],
            'hr_contact_email' => ['nullable', 'email', 'max:190'],
            'hr_contact_phone' => ['nullable', 'string', 'max:40'],
            'verification_methods' => ['sometimes', 'array', 'max:4'],
            'verification_methods.*' => ['string', 'distinct', Rule::in(VerificationMethod::values())],
            'allowed_email_domains' => ['sometimes', 'nullable', 'array', 'max:50'],
            'allowed_email_domains.*' => ['string', 'distinct', 'max:120', 'regex:/^@?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i'],
            'card_number_prefix' => ['nullable', 'string', 'max:40'],
            'card_number_regex' => ['nullable', 'string', 'max:190'],
            'static_convention_code' => ['nullable', 'string', 'min:4', 'max:60'],
            'allow_family_members' => ['sometimes', 'boolean'],
            'max_family_members' => ['sometimes', 'integer', 'between:0,20'],
            'annual_spending_cap_per_beneficiary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'employer_subsidy_enabled' => ['sometimes', 'boolean'],
            'employer_subsidy_type' => ['nullable', Rule::in(SubsidyType::values())],
            'employer_subsidy_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'employer_subsidy_annual_cap' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'subsidy_purpose' => ['sometimes', Rule::in(SubsidyPurpose::values())],
            'split_billing_anonymized' => ['sometimes', 'boolean'],
            'billing_cycle' => ['sometimes', Rule::in(BillingCycle::values())],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'welcome_message_key' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.]+$/'],
            'discount_rules' => ['sometimes', 'array', 'max:100'],
            'discount_rules.*.priority' => ['sometimes', 'integer', 'between:1,65000'],
            'discount_rules.*.rule_type' => ['required', Rule::in(RuleType::values())],
            'discount_rules.*.target_price_list_id' => ['nullable', 'uuid', $this->tenantExists('price_lists')],
            'discount_rules.*.target_category_id' => ['nullable', 'uuid', Rule::exists('product_categories', 'id')->where('tenant_id', $this->tenantId())],
            'discount_rules.*.discount_percentage' => ['required', 'numeric', 'between:0,100'],
            'discount_rules.*.exclude_promotional_items' => ['sometimes', 'boolean'],
            'discount_rules.*.cumulative_with_other_discounts' => ['sometimes', 'boolean'],
            'discount_rules.*.min_cart_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    private function tenantId(): ?string
    {
        return app(TenantContext::class)->getTenantId();
    }
}
