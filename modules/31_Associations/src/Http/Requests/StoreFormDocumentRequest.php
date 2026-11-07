<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Enums\DocumentMode;

final class StoreFormDocumentRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::enum(DocumentMode::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'file' => [Rule::requiredIf($this->input('mode') === DocumentMode::DownloadOnly->value), 'nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:'.config('associations.upload_max_kilobytes')],
        ];
    }
}
