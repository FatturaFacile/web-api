<?php

declare(strict_types=1);

namespace Modules\Quoting\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReorderQuoteRequest extends FormRequest
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
            'section_order' => ['present', 'array'],
            'section_order.*' => ['uuid'],
            'line_order' => ['present', 'array'],
            'line_order.*.id' => ['required', 'uuid'],
            'line_order.*.section_id' => ['nullable', 'uuid'],
        ];
    }
}
