<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'workflow_code' => ['required', 'string', 'max:100'],
            'approvable_type' => ['required', 'string'],
            'approvable_id' => ['required', 'uuid'],
            'context_snapshot' => ['sometimes', 'array'],
        ];
    }
}
