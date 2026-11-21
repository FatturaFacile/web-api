<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AiCognitive\Infrastructure\Services\DraftGenerationService;

final class GenerateDraftRequest extends FormRequest
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
            'purpose' => ['required', Rule::in(DraftGenerationService::Purposes)],
            'language' => ['required', Rule::in(DraftGenerationService::Locales)],
            'facts' => ['required', 'array', 'min:1', 'max:30'],
            'facts.*' => ['nullable', 'string', 'max:500'],
            'instructions' => ['nullable', 'string', 'max:500'],
        ];
    }
}
