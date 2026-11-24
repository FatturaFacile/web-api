<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the sending of an invoice or a quote on a channel (access is decided by module.access).
 */
final class SendDocumentRequest extends FormRequest
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
            'document_type' => ['required', 'in:invoice,quote'],
            'document_id' => ['required', 'uuid'],
            'channel' => ['required', Rule::in(RecipientRules::Channels)],
            'recipient' => ['nullable', ...RecipientRules::for($this->input('channel'))],
            'message' => ['nullable', 'string', 'max:1000'],
            'locale' => ['nullable', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'],
        ];
    }
}
