<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Governance\Models\GovControlTestRun;

/**
 * The result of a test of a control, with the DMS documents that support it as evidence.
 */
final class ControlTestRequest extends FormRequest
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

        return [
            'tested_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'result' => ['required', Rule::in(GovControlTestRun::Results)],
            'sample_size' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'exceptions_found' => ['nullable', 'integer', 'min:0', 'max:1000000', 'lte:sample_size'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'evidence_document_ids' => ['nullable', 'array', 'max:20'],
            'evidence_document_ids.*' => ['uuid', 'distinct', Rule::exists('dms_documents', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'raise_finding' => ['sometimes', 'boolean'],
        ];
    }
}
