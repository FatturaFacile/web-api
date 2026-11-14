<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Governance\Models\GovFinding;

/**
 * An audit finding, on creation (POST) or change (PUT).
 */
final class FindingRequest extends FormRequest
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
            'source' => [$presence, Rule::in(GovFinding::Sources)],
            'audit_id' => ['nullable', 'uuid', 'required_if:source,audit', $this->tenantOwns('gov_audits')],
            'control_id' => ['nullable', 'uuid', 'required_if:source,control_test', $this->tenantOwns('gov_controls')],
            'risk_id' => ['nullable', 'uuid', $this->tenantOwns('gov_risks')],
            'title' => [$presence, 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:4000'],
            'severity' => [$presence, Rule::in(GovFinding::Severities)],
            'recommendation' => ['nullable', 'string', 'max:4000'],
            'owner_id' => ['nullable', 'uuid', $this->tenantMember()],
        ];
    }
}
