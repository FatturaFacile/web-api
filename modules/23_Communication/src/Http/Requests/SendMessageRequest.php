<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the sending of a message built from a template of the company (access is decided by module.access).
 */
final class SendMessageRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
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
            'channel' => ['required', Rule::in(RecipientRules::Channels)],
            'recipient' => ['required', ...RecipientRules::for($this->input('channel'))],
            'template_code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.]{1,99}$/'],
            'locale' => ['nullable', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'],
            'variables' => ['nullable', 'array', 'max:50'],
            'variables.*' => ['nullable', new ScalarText(1000)],
        ];
    }
}
