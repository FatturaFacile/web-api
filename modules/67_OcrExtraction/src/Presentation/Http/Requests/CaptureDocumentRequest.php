<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;

/**
 * Capture of the mobile app: either one `file` (photo or PDF) or the `pages` of one receipt or delivery note in order,
 * plus the metadata of the capture. `client_reference` is the idempotency key of the app's offline queue.
 */
final class CaptureDocumentRequest extends FormRequest
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
            'file' => ['required_without:pages', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360'],
            'pages' => ['required_without:file', 'array', 'min:1', 'max:20'],
            'pages.*' => ['file', 'mimes:jpg,jpeg,png', 'max:15360'],
            'document_category' => ['nullable', Rule::enum(DocumentCategory::class)],
            'client_reference' => ['nullable', 'string', 'max:120'],
            'captured_at' => ['nullable', 'date'],
            'device' => ['nullable', 'string', 'max:120'],
        ];
    }
}
