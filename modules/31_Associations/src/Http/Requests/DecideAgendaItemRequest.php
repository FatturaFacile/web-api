<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DecideAgendaItemRequest extends FormRequest
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
            'approved' => ['required', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
