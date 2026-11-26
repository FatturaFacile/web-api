<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveFiduciaryEntryNoteRequest extends FormRequest
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
            'resolved' => ['required', 'boolean'],
            'resolution_comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
