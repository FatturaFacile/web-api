<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StatementsQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('intercompany.view');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'standard' => ['nullable', Rule::in(['fer30', 'ifrs10', 'oic17'])],
            'statement' => ['nullable', Rule::in(['all', 'balance_sheet', 'income_statement', 'cash_flow'])],
        ];
    }
}
