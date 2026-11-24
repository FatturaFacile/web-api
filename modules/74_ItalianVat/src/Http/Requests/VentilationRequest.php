<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ventilation of the gross takings of a day.
 */
final class VentilationRequest extends FormRequest
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
            'entry_date' => ['required', 'date'],
            'gross_takings' => ['required', 'numeric', 'gt:0'],
            'purchase_basis' => ['required', 'array', 'min:1'],
            'purchase_basis.*.rate' => ['required', 'numeric', 'between:0,100'],
            'purchase_basis.*.amount' => ['required', 'numeric', 'min:0'],
            'purchase_basis.*.nature' => ['nullable', 'string', 'max:6'],
            'section_id' => ['nullable', 'uuid'],
        ];
    }
}
