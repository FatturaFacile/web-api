<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Takings of a day already split by rate.
 */
final class ReceiptsByRateRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.rate' => ['required', 'numeric', 'between:0,100'],
            'lines.*.gross' => ['required', 'numeric', 'gt:0'],
            'lines.*.nature' => ['nullable', 'string', 'max:6'],
            'section_id' => ['nullable', 'uuid'],
        ];
    }
}
