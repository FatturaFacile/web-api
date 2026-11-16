<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Documents\Models\DmsApprovalTemplateStep;

/**
 * Validates an approval template with its ordered steps (access is decided by module.access and the manager check).
 * A PUT only validates the fields it sends; sending `steps` replaces the whole route.
 */
final class SaveApprovalTemplateRequest extends FormRequest
{
    /**
     * Access is decided by module.access and the controller.
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
        $required = $this->isMethod('PUT') ? 'sometimes' : 'required';
        $tenantId = (string) app(TenantContext::class)->getTenantId();

        return [
            'name' => [$required, 'string', 'max:160'],
            'document_type' => ['nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => [$required, 'array', 'min:1', 'max:10'],
            'steps.*.name' => ['required', 'string', 'max:160'],
            'steps.*.approver_type' => ['required', Rule::in(DmsApprovalTemplateStep::ApproverTypes)],
            'steps.*.approver_user_id' => [
                'nullable',
                'required_if:steps.*.approver_type,user',
                'uuid',
                Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId)->whereNull('suspended_at'),
            ],
            'steps.*.approver_role' => ['nullable', 'required_if:steps.*.approver_type,role', 'string', 'max:60', Rule::in(self::deciderRoles())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['steps.*.approver_role.in' => __('documents_errors.approval.role_cannot_decide')];
    }

    /**
     * Roles that can write in the module, and so can decide a step: a role without write access could never approve.
     *
     * @return list<string>
     */
    public static function deciderRoles(): array
    {
        return array_values(array_unique(array_filter((array) config('permissions.modules.documents.write'), static fn (mixed $role): bool => is_string($role) && $role !== '*')));
    }
}
