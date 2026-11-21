<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UnmaskPiiRequest extends FormRequest
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
            'entity_type' => ['required', 'string', Rule::in(array_keys((array) config('field_encryption.models', [])))],
            'entity_id' => ['required', 'string', 'max:64'],
            'field' => ['required', 'string', 'max:64'],
            'justification' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
