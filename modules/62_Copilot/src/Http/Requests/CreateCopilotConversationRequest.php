<?php

declare(strict_types=1);

namespace Modules\Copilot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateCopilotConversationRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:160'],
            'locale' => ['nullable', 'string', 'in:it,de,fr,en,it-CH,de-CH,fr-CH,it-IT'],
            'context_route' => ['nullable', 'string', 'max:255'],
            'context_entity_type' => ['nullable', 'string', 'max:80'],
            'context_entity_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
