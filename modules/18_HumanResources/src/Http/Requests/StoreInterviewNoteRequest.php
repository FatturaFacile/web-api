<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreInterviewNoteRequest extends FormRequest
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
            'interviewed_at' => ['nullable', 'date'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'note' => ['required', 'string', 'max:20000'],
        ];
    }
}
