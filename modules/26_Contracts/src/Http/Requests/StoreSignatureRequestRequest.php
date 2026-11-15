<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * An e-signature request: version, signers in order and provider.
 */
final class StoreSignatureRequestRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_version_id' => ['required', 'uuid', $this->ownRow('contract_document_versions')],
            'provider' => ['sometimes', 'string', 'max:40'],
            'order_mode' => ['sometimes', Rule::in(['sequential', 'parallel'])],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.name' => ['required', 'string', 'max:255'],
            'signers.*.email' => ['required', 'email', 'max:255', 'distinct:ignore_case'],
        ];
    }
}
