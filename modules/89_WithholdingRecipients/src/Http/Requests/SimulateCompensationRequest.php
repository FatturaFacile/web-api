<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\WithholdingRecipients\Domain\Enums\ActivityType;
use Modules\WithholdingRecipients\Domain\Enums\EnasarcoMandate;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Models\RecipientProfile;

/**
 * Real-time preview of a compensation: for a registered recipient, or for ad-hoc facts typed in the simulator.
 */
final class SimulateCompensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', RecipientProfile::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'recipient_profile_id' => ['nullable', 'uuid', Rule::exists('fiscal_recipient_profiles', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'regime' => ['required_without:recipient_profile_id', 'nullable', Rule::enum(RecipientRegime::class)],
            'gross_amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'payment_date' => ['required', 'date'],
            'service_date' => ['nullable', 'date', 'before_or_equal:payment_date'],
            'performance_days' => ['nullable', 'integer', 'between:1,366'],
            'acquisition_expenses' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'birth_date' => ['nullable', 'date'],
            'tax_code' => ['nullable', 'string', 'max:20'],
            'enasarco_mandate' => ['nullable', Rule::enum(EnasarcoMandate::class)],
            'firr_enabled' => ['sometimes', 'boolean'],
            'is_cultural_sector' => ['sometimes', 'boolean'],
            'activity_type' => ['nullable', Rule::enum(ActivityType::class)],
            'has_reduced_certificate' => ['sometimes', 'boolean'],
            'prior_company_income' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'third_party_income' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'prior_avs_base' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'canton' => ['nullable', 'string', 'size:2'],
        ];
    }
}
