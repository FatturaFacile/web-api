<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use App\Enums\Privacy\RopaLegalBasis;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RopaRecordRequest extends FormRequest
{
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'activity_code' => [$required, 'string', 'max:40', Rule::unique('privacy_ropa_records', 'activity_code')->where('tenant_id', $tenantId)->ignore($this->route('id'))],
            'activity_name' => [$required, 'string', 'max:255'],
            'purpose' => [$required, 'string', 'max:5000'],
            'legal_basis' => [$required, Rule::enum(RopaLegalBasis::class)],
            'data_subject_categories' => ['nullable', 'array'],
            'data_subject_categories.*' => ['string', 'max:120'],
            'personal_data_categories' => ['nullable', 'array'],
            'personal_data_categories.*' => ['string', 'max:120'],
            'recipient_categories' => ['nullable', 'array'],
            'recipient_categories.*' => ['string', 'max:120'],
            'cross_border_transfers' => ['nullable', 'array'],
            'cross_border_transfers.*.country_code' => ['required', 'string', 'max:8'],
            'cross_border_transfers.*.adequacy_decision' => ['required', 'boolean'],
            'cross_border_transfers.*.safeguard_mechanism' => ['required', 'string', 'max:255'],
            'technical_organizational_measures' => ['nullable', 'array'],
            'technical_organizational_measures.*' => ['string', 'max:255'],
            'retention_period_months' => [$required, 'integer', 'min:0', 'max:1200'],
            'retention_legal_reference' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
