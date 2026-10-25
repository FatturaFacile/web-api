<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Http\Requests\Concerns\ValidatesUploadContentLength;
use App\Models\Attachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttachmentRequest extends FormRequest
{
    use ValidatesUploadContentLength;

    protected int $maxContentLengthBytes = 25 * 1024 * 1024;

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
            'attachable_type' => ['required', Rule::in(array_keys(Attachment::TARGET_TYPES))],
            'attachable_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:25600', 'mimes:pdf,jpg,jpeg,png,gif,doc,docx,xls,xlsx,csv,txt,xml,zip'],
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
