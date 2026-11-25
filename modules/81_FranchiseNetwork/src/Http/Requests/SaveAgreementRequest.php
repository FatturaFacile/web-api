<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FranchiseNetwork\Domain\Enums\GuaranteeType;
use Modules\FranchiseNetwork\Domain\Enums\Jurisdiction;
use Modules\FranchiseNetwork\Domain\Enums\PaymentMethod;
use Modules\FranchiseNetwork\Http\Requests\Concerns\ScopesToTenant;

final class SaveAgreementRequest extends FormRequest
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
            'store_id' => [$required, 'uuid', $this->tenantExists('frn_stores')],
            'formula_id' => ['nullable', 'uuid', $this->tenantOrGlobalExists('frn_formulas')],
            'agreement_number' => ['sometimes', 'string', 'max:40', $this->tenantUnique('frn_agreements', 'agreement_number')->ignore($this->route('id'))],
            'jurisdiction' => ['sometimes', Rule::in(Jurisdiction::values())],
            'disclosure_delivered_on' => ['nullable', 'date'],
            'signed_on' => ['nullable', 'date'],
            'start_date' => [$required, 'date'],
            'end_date' => [$required, 'date'],
            'auto_renew' => ['sometimes', 'boolean'],
            'renewal_notice_days' => ['sometimes', 'integer', 'min:0', 'max:730'],
            'exclusivity_zone' => ['nullable', 'array'],
            'exclusivity_zone.label' => ['nullable', 'string', 'max:200'],
            'exclusivity_zone.postal_codes' => ['nullable', 'array', 'max:200'],
            'exclusivity_zone.postal_codes.*' => ['string', 'max:12'],
            'exclusivity_zone.radius_km' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'entry_fee' => ['sometimes', 'numeric', 'min:0'],
            'entry_fee_deferral_months' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'min_purchase_amount' => ['sometimes', 'numeric', 'min:0'],
            'min_purchase_source' => ['sometimes', Rule::in(['central_warehouse', 'approved_suppliers'])],
            'approved_supplier_ids' => ['nullable', 'array'],
            'approved_supplier_ids.*' => ['uuid'],
            'guarantee_type' => ['sometimes', Rule::in(GuaranteeType::values())],
            'guarantee_amount' => ['sometimes', 'numeric', 'min:0'],
            'guarantee_reference' => ['nullable', 'string', 'max:80'],
            'guarantee_expires_on' => ['nullable', 'date'],
            'payment_method' => ['sometimes', Rule::in(PaymentMethod::values())],
            'mandate_reference' => ['nullable', 'string', 'max:60'],
            'mandate_signed_on' => ['nullable', 'date'],
            'currency' => ['sometimes', Rule::in(['EUR', 'CHF'])],
            'non_binding_pricing' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
