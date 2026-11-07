<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SendEDocumentRequest extends FormRequest
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
            'document_reference' => ['required', 'string', 'max:190'],
            'recipient_identifier' => ['required', 'string', 'max:190'],
            'payload_base64' => ['required', 'string', 'max:20000000'],
        ];
    }
}
