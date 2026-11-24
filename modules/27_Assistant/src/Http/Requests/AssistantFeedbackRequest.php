<?php

declare(strict_types=1);

namespace Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assistant\Models\AssistantQuestionLog;

/**
 * Feedback of the user on one answer.
 */
final class AssistantFeedbackRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware; the answer must be the caller's own, which the controller checks.
     */
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
            'rating' => ['required', 'string', Rule::in(AssistantQuestionLog::Ratings)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
