<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateNotesRequest extends FormRequest
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
            'tables' => ['sometimes', 'array'],
            'tables.*' => ['array'],
            'sections' => ['sometimes', 'array', 'max:60'],
            'sections.*.key' => ['required_with:sections', 'string', 'max:100'],
            'sections.*.title' => ['sometimes', 'string', 'max:200'],
            'sections.*.body' => ['required_with:sections', 'string', 'max:20000'],
        ];
    }
}
