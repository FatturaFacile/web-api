<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Workflow\Services\WorkflowAssigneeResolver;

/**
 * An escalation rule: when a pending approval step is reminded, to whom and when it escalates, and what the engine
 * decides by itself when the time limit runs out (access is decided by module.access:workflow_design).
 */
final class SaveEscalationRuleRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = $this->input('escalate_to_type');
            $target = $this->input('escalate_to_id');

            if (in_array($type, ['specific_role', 'specific_user'], true) && ! is_string($target)) {
                $validator->errors()->add('escalate_to_id', 'A target is required for this escalation type.');

                return;
            }

            if ($type === 'specific_user' && (! Str::isUuid($target) || ! app(WorkflowAssigneeResolver::class)->isMember($target, (string) app(TenantContext::class)->getTenantId()))) {
                $validator->errors()->add('escalate_to_id', 'The user is not a member of this company.');
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'reminder_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'escalation_hours' => ['required', 'integer', 'min:1', 'max:8760', 'gt:reminder_hours'],
            'escalate_to_type' => ['required', Rule::in(['reporting_manager', 'specific_role', 'specific_user'])],
            'escalate_to_id' => ['nullable', 'string', 'max:255'],
            'auto_action' => ['nullable', Rule::in(['none', 'auto_approve', 'auto_reject'])],
        ];
    }
}
