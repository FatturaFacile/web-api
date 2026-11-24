<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveAiSuggestionRequest extends FormRequest
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
            'edited_payload' => ['nullable', 'array'],
            'feedback' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
