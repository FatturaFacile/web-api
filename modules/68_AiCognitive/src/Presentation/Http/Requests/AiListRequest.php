<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\Enums\SuggestionStatus;

/**
 * Filters of the workbench queue and of the usage and audit view.
 */
final class AiListRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(SuggestionStatus::class)],
            'kind' => ['nullable', Rule::enum(SuggestionKind::class)],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'event_type' => ['nullable', 'string', 'max:40'],
            'verify_chain' => ['nullable', 'boolean'],
            'horizon_days' => ['nullable', 'integer', 'min:1', 'max:400'],
        ];
    }
}
