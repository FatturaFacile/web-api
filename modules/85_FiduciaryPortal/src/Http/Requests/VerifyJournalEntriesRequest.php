<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyJournalEntriesRequest extends FormRequest
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
            'entry_ids' => ['required', 'array', 'min:1', 'max:200'],
            'entry_ids.*' => ['required', 'uuid'],
            'verified' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
