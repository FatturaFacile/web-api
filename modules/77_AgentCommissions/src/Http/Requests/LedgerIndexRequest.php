<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AgentCommissions\Models\CommissionEntry;

final class LedgerIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', CommissionEntry::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent_profile_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'max:30'],
            'accrual_basis' => ['nullable', 'in:invoiced,collected'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
