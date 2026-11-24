<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The annual internal audit plan, on creation (POST) or change (PUT). There is one plan per year.
 */
final class AuditPlanRequest extends FormRequest
{
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
        $tenantId = app(TenantContext::class)->getTenantId();
        $unique = Rule::unique('gov_audit_plans', 'year')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'));

        if ($this->isMethod('PUT')) {
            $unique->ignore((string) $this->route('id'));
        }

        return [
            'year' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'integer', 'between:2000,2100', $unique],
            'title' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
