<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the input of the send edocument operation (authorization is decided by module.access).
 */
final class SendEDocumentRequest extends FormRequest
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
            'document_reference' => ['required', 'string', 'max:190'],
            'recipient_identifier' => ['required', 'string', 'max:190'],
            'payload_base64' => ['required', 'string', 'max:20000000'],
        ];
    }
}
