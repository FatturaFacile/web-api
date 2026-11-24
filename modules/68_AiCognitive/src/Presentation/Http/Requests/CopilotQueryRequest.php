<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CopilotQueryRequest extends FormRequest
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
            'question' => ['required', 'string', 'min:3', 'max:2000'],
            'mode' => ['nullable', Rule::in(['auto', 'analytics', 'knowledge'])],
        ];
    }
}
