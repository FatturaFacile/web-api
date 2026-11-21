<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveSafetyAuditRequest extends FormRequest
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
        return [
            'audit_date' => ['required', 'date'],
            'answers' => ['required', 'array', 'size:10'],
            'answers.*' => ['required', 'string', 'in:yes,partial,no'],
            'corrective_actions' => ['nullable', 'array'],
            'corrective_actions.*.point' => ['required', 'integer', 'between:1,10'],
            'corrective_actions.*.description' => ['required', 'string', 'max:1000'],
            'corrective_actions.*.assignee_id' => ['nullable', 'uuid'],
            'corrective_actions.*.due_date' => ['nullable', 'date'],
        ];
    }
}
