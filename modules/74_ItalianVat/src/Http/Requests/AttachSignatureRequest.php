<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Detached signature of the LIPE file and the certificate of the signer.
 */
final class AttachSignatureRequest extends FormRequest
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
            'signature' => ['required', 'string'],
            'certificate' => ['required', 'string'],
        ];
    }
}
