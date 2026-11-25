<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MarginRequest extends FormRequest
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
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'contract_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'group_by' => ['nullable', Rule::in(['product', 'contract'])],
        ];
    }
}
