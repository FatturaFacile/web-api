<?php

declare(strict_types=1);

namespace Modules\Copilot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a prompt sent to the Copilot (access is decided by module.access:copilot; the conversation is checked to be the
 * caller's own by the controller).
 */
final class SendCopilotMessageRequest extends FormRequest
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
            'prompt' => ['required', 'string', 'max:'.(int) config('copilot.limits.max_prompt_chars', 4000)],
            'locale' => ['nullable', 'string', 'in:it,de,fr,en,it-CH,de-CH,fr-CH,it-IT'],
            'context' => ['nullable', 'array'],
            'context.route' => ['nullable', 'string', 'max:255'],
            'context.entity_type' => ['nullable', 'string', 'max:80'],
            'context.entity_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
