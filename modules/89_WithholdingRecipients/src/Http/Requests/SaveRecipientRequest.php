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

final class SaveRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', RecipientProfile::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $taxCode = Rule::unique('fiscal_recipient_profiles', 'tax_code')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        if (! $creating) {
            $taxCode->ignore((string) $this->route('id'));
        }

        return [
            'display_name' => [$required, 'string', 'max:200'],
            'regime' => [$required, Rule::enum(RecipientRegime::class)],
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'tax_code' => ['nullable', 'string', 'max:20', $taxCode],
            'vat_number' => ['nullable', 'string', 'max:20'],
            'avs_number' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'country_of_residence' => ['nullable', 'string', 'size:2'],
            'canton' => ['nullable', 'string', 'size:2'],
            'enasarco_mandate' => ['nullable', Rule::enum(EnasarcoMandate::class)],
            'firr_enabled' => ['sometimes', 'boolean'],
            'is_cultural_sector' => ['sometimes', 'boolean'],
            'activity_type' => ['nullable', Rule::enum(ActivityType::class)],
            'cu_causal' => ['nullable', Rule::in((array) config('withholding_recipients.cu_causals'))],
            'third_party_ytd_income' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'third_party_income_year' => ['nullable', 'integer', 'between:2000,2100', 'required_with:third_party_ytd_income'],
            'third_party_declared_on' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
