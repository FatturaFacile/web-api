<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the request to send a document through an approval template (access is decided by module.access).
 */
final class RequestApprovalRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
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
        return [
            'template_id' => ['required', 'uuid', Rule::exists('dms_approval_templates', 'id')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
