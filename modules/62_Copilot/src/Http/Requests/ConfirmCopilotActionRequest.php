<?php

declare(strict_types=1);

namespace Modules\Copilot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the confirmation of a staged action: optional overrides of the fields the card declared editable.
 */
final class ConfirmCopilotActionRequest extends FormRequest
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
            'overrides' => ['nullable', 'array', 'max:60'],
        ];
    }
}
