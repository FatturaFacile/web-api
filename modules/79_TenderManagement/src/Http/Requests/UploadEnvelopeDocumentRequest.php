<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An envelope document upload (multipart).
 */
final class UploadEnvelopeDocumentRequest extends FormRequest
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
            'doc_key' => ['required', 'string', 'max:60'],
            'file' => ['required', 'file', 'max:'.(int) config('tender_management.uploads.max_kilobytes', 20480)],
            'label' => ['nullable', 'string', 'max:160'],
        ];
    }
}
