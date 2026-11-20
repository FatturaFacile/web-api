<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RejectExtractionRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
