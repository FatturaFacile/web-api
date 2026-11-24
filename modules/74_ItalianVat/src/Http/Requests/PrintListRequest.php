<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters of the list of definitive prints.
 */
final class PrintListRequest extends FormRequest
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
            'section_id' => ['nullable', 'uuid'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ];
    }
}
