<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Workflow\Services\WorkflowGraphValidator;

final class SaveWorkflowNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Assignees named by id must be members of the company: a user of another company cannot be asked to approve.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = $this->input('assignee_type');
            $value = $this->input('assignee_id');

            if (! in_array($type, ['direct_user', 'user_list', 'user_list_sequential'], true) || ! is_string($value) || $value === '') {
                return;
            }

            $ids = array_map('trim', explode(',', $value));

            if ($type === 'direct_user' && count($ids) !== 1) {
                $validator->errors()->add('assignee_id', 'A direct user node takes exactly one user id.');

                return;
            }

            foreach ($ids as $id) {
                if (! Str::isUuid($id) || ! DB::table('tenant_user')->where('tenant_id', app(TenantContext::class)->getTenantId())->where('user_id', $id)->exists()) {
                    $validator->errors()->add('assignee_id', "User [{$id}] is not a member of this company.");
                }
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'node_key' => [$presence, 'string', 'max:100', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('workflow_nodes', 'node_key')->where('workflow_definition_id', (string) $this->route('id'))->ignore($this->route('nodeId'))],
            'name' => [$presence, 'string', 'max:255'],
            'node_type' => [$presence, Rule::in(WorkflowGraphValidator::NodeTypes)],
            'approval_type' => ['sometimes', 'nullable', Rule::in(WorkflowGraphValidator::ApprovalTypes)],
            'assignee_type' => ['sometimes', 'nullable', Rule::in(WorkflowGraphValidator::AssigneeTypes)],
            'assignee_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'timeout_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:8760'],
            'escalation_rule_id' => ['sometimes', 'nullable', 'uuid', TenantRule::exists('workflow_escalation_rules')],
            'ui_metadata' => ['sometimes', 'array'],
        ];
    }
}
