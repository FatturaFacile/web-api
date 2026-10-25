<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Models\Attachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Guard against excessive request content lengths before processing.
     */
    protected function prepareForValidation(): void
    {
        $contentLength = (int) $this->header('Content-Length', 0);
        if ($contentLength > 20 * 1024 * 1024) {
            abort(413, 'Payload Too Large');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attachable_type' => ['required', Rule::in(array_keys(Attachment::TARGET_TYPES))],
            'attachable_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,gif,doc,docx,xls,xlsx,csv,txt,xml,zip'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['file.mimes' => __('documents.file_types')];
    }
}
