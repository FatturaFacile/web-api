<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AgentCommissions\Models\Statement;

final class StatementIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Statement::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent_profile_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'max:20'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
