<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AgentCommissions\Models\Statement;

final class PayStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('settle', Statement::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_on' => ['nullable', 'date'],
        ];
    }
}
