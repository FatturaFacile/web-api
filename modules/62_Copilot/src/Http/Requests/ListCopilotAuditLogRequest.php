<?php

declare(strict_types=1);

namespace Modules\Copilot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListCopilotAuditLogRequest extends FormRequest
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
            'action' => ['nullable', 'string', 'in:prompt,action_proposed,action_confirmed,action_executed,action_failed,action_rejected'],
            'user_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
