<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AgentCommissions\Models\AgentYearBalance;

final class EnasarcoOverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', AgentYearBalance::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'agent_profile_id' => ['nullable', 'uuid'],
        ];
    }
}
