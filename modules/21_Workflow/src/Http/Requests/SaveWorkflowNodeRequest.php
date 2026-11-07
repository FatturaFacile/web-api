<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Workflow\Services\WorkflowGraphValidator;

final class SaveWorkflowNodeRequest extends FormRequest
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
            'ui_metadata' => ['sometimes', 'array'],
        ];
    }
}
