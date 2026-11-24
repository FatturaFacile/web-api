<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Models\Consent;

/**
 * A GDPR consent event (grant or withdrawal) for one contact or one lead of the active tenant, with its source and
 * capture time as evidence.
 */
final class StoreConsentRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contact_id' => ['nullable', 'uuid', 'required_without:lead_id', 'prohibits:lead_id', $this->tenantOwns('contacts')],
            'lead_id' => ['nullable', 'uuid', 'required_without:contact_id', 'prohibits:contact_id', $this->tenantOwns('crm_leads')],
            'channel' => ['required', Rule::in(Consent::Channels)],
            'purpose' => ['required', Rule::in(Consent::Purposes)],
            'status' => ['required', Rule::in([Consent::Granted, Consent::Withdrawn])],
            'source' => ['required', Rule::in(Consent::Sources)],
            'captured_at' => ['nullable', 'date', 'before_or_equal:now'],
            'evidence' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
