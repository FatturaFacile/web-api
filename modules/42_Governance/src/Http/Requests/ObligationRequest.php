<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Governance\Models\GovObligation;

/**
 * An entry of the compliance calendar, on creation (POST) or change (PUT).
 */
final class ObligationRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'framework' => [$presence, Rule::in(GovObligation::Frameworks)],
            'jurisdiction' => [$presence, Rule::in(GovObligation::Jurisdictions)],
            'title' => [$presence, 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:4000'],
            'legal_reference' => ['nullable', 'string', 'max:190'],
            'recurrence' => [$presence, Rule::in(GovObligation::Recurrences)],
            'due_date' => [$presence, 'date_format:Y-m-d'],
            'reminder_days' => ['nullable', 'integer', 'between:0,365'],
            'owner_id' => ['nullable', 'uuid', $this->tenantMember()],
            'evidence_document_id' => ['nullable', 'uuid', Rule::exists('dms_documents', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
