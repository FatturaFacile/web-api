<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\OcrExtraction\Domain\Enums\ExtractionJobStatus;

final class InboxQueryRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(array_map(static fn (ExtractionJobStatus $status): string => $status->value, ExtractionJobStatus::inbox()))],
            'job_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
