<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Governance\Models\GovAudit;

/**
 * An internal audit, on creation (POST) or change (PUT).
 */
final class AuditRequest extends FormRequest
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

        return [
            'audit_plan_id' => ['nullable', 'uuid', $this->tenantOwns('gov_audit_plans')],
            'title' => [$presence, 'string', 'max:190'],
            'audit_type' => [$presence, Rule::in(GovAudit::Types)],
            'scope' => [$presence, 'string', 'max:4000'],
            'objectives' => ['nullable', 'string', 'max:4000'],
            'lead_auditor_id' => [$presence, 'uuid', $this->tenantMember()],
            'team_member_ids' => ['nullable', 'array', 'max:30'],
            'team_member_ids.*' => ['uuid', 'distinct', $this->tenantMember()],
            'planned_start' => [$presence, 'date_format:Y-m-d'],
            'planned_end' => [$presence, 'date_format:Y-m-d', 'after_or_equal:planned_start'],
            'conclusion' => ['nullable', 'string', 'max:8000'],
        ];
    }
}
