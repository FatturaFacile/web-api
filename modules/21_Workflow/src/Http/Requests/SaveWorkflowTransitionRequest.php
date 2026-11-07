<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Workflow\Services\WorkflowGraphValidator;

/**
 * A transition between two nodes of the definition in the route.
 */
final class SaveWorkflowTransitionRequest extends FormRequest
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
        $ofDefinition = Rule::exists('workflow_nodes', 'id')->where('workflow_definition_id', (string) $this->route('id'))->whereNull('deleted_at');

        return [
            'from_node_id' => [$presence, 'uuid', $ofDefinition],
            'to_node_id' => [$presence, 'uuid', $ofDefinition, 'different:from_node_id'],
            'action_trigger' => [$presence, Rule::in(WorkflowGraphValidator::ActionTriggers)],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'condition_rules' => ['sometimes', 'array'],
        ];
    }
}
