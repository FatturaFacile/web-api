<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fulfilment of an obligation of the compliance calendar, with the evidence document of the DMS.
 */
final class ObligationCompleteRequest extends FormRequest
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
            'completed_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'evidence_document_id' => ['nullable', 'uuid', Rule::exists('dms_documents', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
