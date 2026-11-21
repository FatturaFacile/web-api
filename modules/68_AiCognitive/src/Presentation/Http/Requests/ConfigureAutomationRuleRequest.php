<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;

final class ConfigureAutomationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The workflows a rule can govern: one per kind of suggestion that exists.
     *
     * @return list<string>
     */
    public static function workflows(): array
    {
        return array_values(array_unique(array_map(static fn (SuggestionKind $kind): string => $kind->workflow(), SuggestionKind::cases())));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'auto_approve_enabled' => ['required', 'boolean'],
            'auto_approve_min_confidence' => ['required', 'numeric', 'min:0.5', 'max:1'],
            'max_auto_amount' => ['nullable', 'numeric', 'min:0'],
            'data_classification' => ['required', Rule::enum(DataClassification::class)],
            'preferred_provider' => ['nullable', Rule::enum(AiProviderType::class)],
        ];
    }
}
