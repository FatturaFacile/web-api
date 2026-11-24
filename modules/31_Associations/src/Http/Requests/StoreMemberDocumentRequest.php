<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreMemberDocumentRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('associations.upload_max_kilobytes')],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:50'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
            'visible_to_member' => ['sometimes', 'boolean'],
        ];
    }
}
