<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Models\Alert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$presence, 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'severity' => ['sometimes', Rule::in(['info', 'warning', 'critical'])],
            'alertable_type' => ['nullable', 'required_with:alertable_id', Rule::in(array_keys(Alert::TARGET_TYPES))],
            'alertable_id' => ['nullable', 'required_with:alertable_type', 'uuid'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
