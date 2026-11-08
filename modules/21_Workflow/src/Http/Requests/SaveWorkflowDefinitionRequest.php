<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Workflow\Services\WorkflowGraphValidator;

/**
 * A workflow definition. On creation the graph can be sent inline: nodes by node_key, transitions between node keys.
 * The code is fixed once created; a new version of a code is a new definition with the same code.
 */
final class SaveWorkflowDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $presence = $isCreate ? 'required' : 'sometimes';

        return [
            'code' => [$isCreate ? 'required' : 'prohibited', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'name' => [$presence, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'target_entity_type' => [$presence, 'string', 'max:255'],
            'configuration' => ['sometimes', 'array'],
            'is_active' => [$isCreate ? 'prohibited' : 'sometimes', 'boolean'],

            'nodes' => [$isCreate ? 'sometimes' : 'prohibited', 'array', 'max:100'],
            'nodes.*.node_key' => ['required', 'string', 'max:100', 'distinct'],
            'nodes.*.name' => ['required', 'string', 'max:255'],
            'nodes.*.node_type' => ['required', Rule::in(WorkflowGraphValidator::NodeTypes)],
            'nodes.*.approval_type' => ['nullable', Rule::in(WorkflowGraphValidator::ApprovalTypes)],
            'nodes.*.assignee_type' => ['nullable', Rule::in(WorkflowGraphValidator::AssigneeTypes)],
            'nodes.*.assignee_id' => ['nullable', 'string', 'max:255'],
            'nodes.*.timeout_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'nodes.*.escalation_rule_id' => ['nullable', 'uuid', TenantRule::exists('workflow_escalation_rules')],

            'transitions' => [$isCreate ? 'sometimes' : 'prohibited', 'array', 'max:300'],
            'transitions.*.from' => ['required', 'string', 'max:100'],
            'transitions.*.to' => ['required', 'string', 'max:100'],
            'transitions.*.action_trigger' => ['required', Rule::in(WorkflowGraphValidator::ActionTriggers)],
            'transitions.*.priority' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'transitions.*.condition_rules' => ['sometimes', 'array'],

            'publish' => [$isCreate ? 'sometimes' : 'prohibited', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $keys = collect($this->input('nodes', []))->pluck('node_key')->filter()->all();

                foreach ((array) $this->input('transitions', []) as $index => $transition) {
                    foreach (['from', 'to'] as $end) {
                        if (isset($transition[$end]) && ! in_array($transition[$end], $keys, true)) {
                            $validator->errors()->add("transitions.{$index}.{$end}", "Unknown node_key [{$transition[$end]}].");
                        }
                    }
                }
            },
        ];
    }
}
