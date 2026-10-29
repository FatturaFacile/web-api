<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateEcoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'reason' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'change_type' => ['nullable', 'string', 'in:bom_change,routing_change,cost_change'],
            'effective_date' => ['nullable', 'date'],
            'change_payload' => ['nullable', 'array'],
        ];
    }
}
