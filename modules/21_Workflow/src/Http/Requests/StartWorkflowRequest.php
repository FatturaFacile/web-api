<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'workflow_code' => ['required', 'string', 'max:100'],
            'approvable_type' => ['required', 'string', Rule::in($this->acceptedApprovableTypes())],
            'approvable_id' => ['required', 'uuid'],
            'context_snapshot' => ['sometimes', 'array'],
        ];
    }

    /**
     * The entry of config/workflow.php for the requested type, given by alias or by class name.
     *
     * @return array{class: class-string, module: string}
     */
    public function approvableDefinition(): array
    {
        $type = $this->string('approvable_type')->value();
        $approvables = (array) config('workflow.approvables', []);

        return $approvables[$type] ?? collect($approvables)->first(static fn (array $entry): bool => $entry['class'] === $type);
    }

    /**
     * @return list<string>
     */
    private function acceptedApprovableTypes(): array
    {
        $approvables = (array) config('workflow.approvables', []);

        return [...array_keys($approvables), ...array_column($approvables, 'class')];
    }
}
