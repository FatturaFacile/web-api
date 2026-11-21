<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;

final class ExtractCognitiveRequest extends FormRequest
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
            'kind' => ['required', Rule::in(array_map(static fn (SuggestionKind $kind): string => $kind->value, array_filter(SuggestionKind::cases(), static fn (SuggestionKind $kind): bool => $kind->isDraftEntity())))],
            'source_type' => ['required', Rule::in(['email', 'rfq', 'delivery_note', 'support_ticket', 'document'])],
            'text' => ['required', 'string', 'min:10', 'max:20000'],
            'source_reference' => ['nullable', 'string', 'max:120'],
            'locale' => ['nullable', Rule::in(['it', 'en', 'de', 'fr'])],
        ];
    }
}
