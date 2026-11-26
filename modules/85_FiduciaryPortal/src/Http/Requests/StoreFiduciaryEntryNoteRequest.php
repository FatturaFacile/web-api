<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFiduciaryEntryNoteRequest extends FormRequest
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
            'journal_entry_id' => ['required', 'uuid'],
            'parent_note_id' => ['nullable', 'uuid'],
            'body' => ['required', 'string', 'min:2', 'max:3000'],
        ];
    }
}
